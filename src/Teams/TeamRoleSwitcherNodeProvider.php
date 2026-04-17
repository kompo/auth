<?php

namespace Kompo\Auth\Teams;

use Condoedge\Utils\Kompo\LazyHierarchy\LazyHierarchyPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Teams\Cache\AuthCacheLayer;
use Kompo\Auth\Teams\CacheKeyBuilder;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;

class TeamRoleSwitcherNodeProvider
{
    private const ROOT_KEY = LazyHierarchyPayload::ROOT_KEY;
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;
    private const LOOKAHEAD_DEPTH = 1;
    private const DEFAULT_LOOKAHEAD_BUDGET = 80;
    private const MAX_SCAN_PAGES = 6;
    private const MAX_SEARCH_SCAN_PAGES = 30;
    private const MAX_PARENT_DEPTH = 50;

    private array $roleNameCache = [];

    public function __construct(private AuthCacheLayer $cache) {}

    public function bootstrap($user, int|string|null $profile, string $mode, int $limit, int $lookaheadBudget): array
    {
        $mode = $this->normalizeMode($mode);
        $limit = $this->normalizeLimit($limit);
        $lookaheadBudget = max($limit, min(120, $lookaheadBudget ?: self::DEFAULT_LOOKAHEAD_BUDGET));

        $payload = $this->emptyPayload($mode);
        $nodeBudget = $lookaheadBudget;
        $currentPathIds = $this->currentPathIds();

        $this->appendLevel(
            payload: $payload,
            user: $user,
            profile: $profile,
            mode: $mode,
            parentId: null,
            limit: $limit,
            cursor: null,
            lookaheadDepth: self::LOOKAHEAD_DEPTH,
            nodeBudget: $nodeBudget,
            currentPathIds: $currentPathIds,
        );

        $this->appendCurrentPath($payload, $user, $profile, $mode, $limit, $currentPathIds, $nodeBudget);

        return $payload->toArray();
    }

    public function children(
        $user,
        int|string|null $profile,
        string $mode,
        ?int $parentId,
        int $limit,
        ?int $cursor,
        int $lookaheadBudget,
    ): array {
        $mode = $this->normalizeMode($mode);
        $limit = $this->normalizeLimit($limit);
        $nodeBudget = max($limit, min(120, $lookaheadBudget ?: self::DEFAULT_LOOKAHEAD_BUDGET));
        $payload = $this->emptyPayload($mode, $parentId === null);

        $this->appendLevel(
            payload: $payload,
            user: $user,
            profile: $profile,
            mode: $mode,
            parentId: $parentId,
            limit: $limit,
            cursor: $cursor,
            lookaheadDepth: self::LOOKAHEAD_DEPTH,
            nodeBudget: $nodeBudget,
            currentPathIds: $this->currentPathIds(),
            append: (int) ($cursor ?? 0) > 0,
        );

        return $payload->toArray();
    }

    public function search($user, int|string|null $profile, string $mode, string $search, int $limit): array
    {
        $mode = $this->normalizeMode($mode);
        $limit = $this->normalizeLimit($limit);
        $payload = $this->emptyPayload($mode);
        $search = trim($search);

        if ($search === '') {
            return $this->bootstrap($user, $profile, $mode, $limit, self::DEFAULT_LOOKAHEAD_BUDGET);
        }

        $currentPathIds = $this->currentPathIds();
        $nodes = collect();
        $offset = 0;
        $scanPages = 0;
        $take = max($limit * 3, self::MAX_LIMIT);

        while ($nodes->count() < $limit && $scanPages < self::MAX_SEARCH_SCAN_PAGES) {
            $teams = $this->searchTeams($mode, $search, $take, $offset);

            if ($teams->isEmpty()) {
                break;
            }

            $decorated = $this->decorateTeams($teams, $user, $profile, $mode, $currentPathIds)
                ->filter(fn($node) => $node['isSelectable'] && $this->hasSwitchableRole($node));

            $nodes = $nodes->concat($decorated)->take($limit)->values();
            $offset += $teams->count();
            $scanPages++;

            if ($teams->count() < $take) {
                break;
            }
        }

        foreach ($nodes as $node) {
            $payload
                ->addNode($node)
                ->appendChild(self::ROOT_KEY, $node['id']);
        }

        $payload->setPaging(self::ROOT_KEY, null, $nodes->count(), $limit);

        return $payload->toArray();
    }

    private function appendLevel(
        LazyHierarchyPayload $payload,
        $user,
        int|string|null $profile,
        string $mode,
        ?int $parentId,
        int $limit,
        ?int $cursor,
        int $lookaheadDepth,
        int &$nodeBudget,
        array $currentPathIds,
        bool $append = false,
    ): void {
        if ($nodeBudget <= 0) {
            return;
        }

        $parentKey = $this->parentKey($parentId);
        $page = $this->visibleChildrenPage($user, $profile, $mode, $parentId, $limit, $cursor, $currentPathIds);
        $nodeIds = [];

        foreach ($page['nodes'] as $node) {
            if ($nodeBudget <= 0) {
                break;
            }

            $payload->addNode($node);
            $nodeIds[] = $node['id'];
            $nodeBudget--;
        }

        $payload
            ->setChildren($parentKey, $nodeIds, $append)
            ->setPaging($parentKey, $page['nextCursor'], $page['total'], $limit, $append);

        if ($lookaheadDepth <= 0) {
            return;
        }

        foreach ($page['nodes'] as $node) {
            if ($nodeBudget <= 0) {
                break;
            }

            if (!$node['hasChildren']) {
                continue;
            }

            $this->appendLevel(
                payload: $payload,
                user: $user,
                profile: $profile,
                mode: $mode,
                parentId: $node['teamId'],
                limit: $limit,
                cursor: null,
                lookaheadDepth: $lookaheadDepth - 1,
                nodeBudget: $nodeBudget,
                currentPathIds: $currentPathIds,
            );
        }
    }

    private function visibleChildrenPage(
        $user,
        int|string|null $profile,
        string $mode,
        ?int $parentId,
        int $limit,
        ?int $cursor,
        array $currentPathIds,
    ): array {
        $offset = max(0, (int) ($cursor ?? 0));
        $scanOffset = $offset;
        $visible = collect();
        $scanPages = 0;
        $total = $this->childrenBaseQuery($mode, $parentId, $user, $profile)->count();

        while ($visible->count() < $limit && $scanPages < self::MAX_SCAN_PAGES) {
            $rawTeams = $this->childrenBaseQuery($mode, $parentId, $user, $profile)
                ->skip($scanOffset)
                ->take(max($limit * 2, self::DEFAULT_LIMIT))
                ->get();

            if ($rawTeams->isEmpty()) {
                break;
            }

            $decorated = $this->decorateTeams($rawTeams, $user, $profile, $mode, $currentPathIds)
                ->filter(fn($node) => $this->isVisibleNode($node, $mode));

            $visible = $visible->concat($decorated)->take($limit)->values();
            $scanOffset += $rawTeams->count();
            $scanPages++;

            if ($rawTeams->count() < max($limit * 2, self::DEFAULT_LIMIT)) {
                break;
            }
        }

        return [
            'nodes' => $visible,
            'nextCursor' => $scanOffset < $total ? $scanOffset : null,
            'total' => $total,
        ];
    }

    private function appendCurrentPath(
        LazyHierarchyPayload $payload,
        $user,
        int|string|null $profile,
        string $mode,
        int $limit,
        array $currentPathIds,
        int &$nodeBudget,
    ): void {
        if (empty($currentPathIds)) {
            return;
        }

        $teamsById = $this->teamQuery()
            ->whereIn('teams.id', $currentPathIds)
            ->get()
            ->keyBy('id');

        if ($teamsById->isEmpty()) {
            return;
        }

        $currentTeam = $teamsById->get((int) end($currentPathIds));

        if (!$currentTeam || !$this->isSelectableForMode($currentTeam, $mode)) {
            return;
        }

        $nodesByTeamId = $this->decorateTeams($teamsById->values(), $user, $profile, $mode, $currentPathIds)
            ->keyBy('teamId');

        $currentNode = $nodesByTeamId->get((int) end($currentPathIds));

        if (!$currentNode || !$this->hasSwitchableRole($currentNode)) {
            return;
        }

        foreach ($currentPathIds as $index => $teamId) {
            $node = $nodesByTeamId->get($teamId);

            if (!$node) {
                continue;
            }

            $payload->addNode($node);

            $parentId = $index === 0 ? null : (int) $currentPathIds[$index - 1];
            $parentKey = $this->parentKey($parentId);
            $payload->prependChild($parentKey, $node['id']);

            if ($index < count($currentPathIds) - 1) {
                $payload->expand($node['id']);
                $this->appendLevel(
                    payload: $payload,
                    user: $user,
                    profile: $profile,
                    mode: $mode,
                    parentId: $teamId,
                    limit: $limit,
                    cursor: null,
                    lookaheadDepth: 0,
                    nodeBudget: $nodeBudget,
                    currentPathIds: $currentPathIds,
                );
            }
        }
    }

    private function decorateTeams(Collection $teams, $user, int|string|null $profile, string $mode, array $currentPathIds): Collection
    {
        if ($teams->isEmpty()) {
            return collect();
        }

        $accessByTeam = $this->resolveRolesForCandidates($teams, $user, $profile, $mode);
        $visibleChildrenCounts = $mode === TeamAccessHierarchyBuilder::MODE_TEAMS
            ? $this->visibleDirectChildrenCounts($teams->pluck('id'), $user, $profile, $mode)
            : [];

        return $teams->map(function ($team) use ($accessByTeam, $visibleChildrenCounts, $mode, $currentPathIds) {
            $teamId = (int) $team->id;
            $isCommittee = $this->isCommittee($team);
            $isSelectable = $this->isSelectableForMode($team, $mode);
            $access = $isSelectable ? ($accessByTeam[$teamId] ?? []) : [];
            $roles = $access['roles'] ?? [];
            $switchRole = $access['switchRole'] ?? null;
            $childrenCount = $mode === TeamAccessHierarchyBuilder::MODE_TEAMS
                ? (int) ($visibleChildrenCounts[$teamId] ?? 0)
                : $this->countDirectChildren($teamId, $mode);

            return [
                'id' => $this->nodeId($teamId),
                'teamId' => $teamId,
                'parentId' => $team->parent_team_id ? (int) $team->parent_team_id : null,
                'name' => $team->team_name,
                'parentName' => $team->relationLoaded('parentTeam') ? $team->parentTeam?->team_name : null,
                'isCurrent' => $isSelectable && currentTeamRole() && currentTeamRole()->team_id == $teamId,
                'isInCurrentPath' => in_array($teamId, $currentPathIds, true),
                'isCommittee' => $isCommittee,
                'isSelectable' => $isSelectable,
                'hasChildren' => $childrenCount > 0,
                'childrenCount' => $childrenCount,
                'committeeCount' => $mode === TeamAccessHierarchyBuilder::MODE_COMMITTEES ? $this->countDirectCommittees($teamId) : 0,
                'level' => $this->teamLevelValue($team),
                'levelLabel' => $this->teamLevelLabel($team, $isCommittee),
                'levelKey' => $this->teamLevelKey($team, $isCommittee),
                'roles' => $roles,
                'switchRole' => $switchRole,
            ];
        })->values();
    }

    private function resolveRolesForCandidates(Collection $candidateTeams, $user, int|string|null $profile, string $mode): array
    {
        $candidateIds = $candidateTeams->pluck('id')->map(fn($id) => (int) $id)->values();

        if ($candidateIds->isEmpty()) {
            return [];
        }

        $candidateParents = $candidateTeams->mapWithKeys(fn($team) => [
            (int) $team->id => $team->parent_team_id ? (int) $team->parent_team_id : null,
        ]);
        $ancestorsByCandidate = $this->ancestorIdsByCandidate($candidateIds->all());
        $activeTeamRoles = $this->activeTeamRoles($user, $profile);
        $selectableRoleTeamIdsByRole = $activeTeamRoles
            ->filter(fn($teamRole) => $teamRole->team
                && $teamRole->getRoleHierarchyAccessBelow()
                && $this->isSelectableForMode($teamRole->team, $mode))
            ->groupBy('role')
            ->map(fn($roles) => $roles->pluck('team_id')->map(fn($id) => (int) $id)->flip());
        $rolesByTeam = [];

        foreach ($candidateIds as $candidateId) {
            $candidateAncestors = $ancestorsByCandidate->get($candidateId, collect())->flip();
            $candidateParentId = $candidateParents->get($candidateId);

            foreach ($activeTeamRoles as $teamRole) {
                $grantingTeamId = (int) $teamRole->team_id;
                $isDirectGrant = $grantingTeamId === $candidateId;
                $grantsAccess = $isDirectGrant;

                if (!$grantsAccess && $teamRole->getRoleHierarchyAccessBelow()) {
                    $grantsAccess = $candidateAncestors->has($grantingTeamId);
                }

                if (!$grantsAccess && $teamRole->getRoleHierarchyAccessNeighbors()) {
                    $roleTeamParentId = $teamRole->team?->parent_team_id ? (int) $teamRole->team->parent_team_id : null;
                    $grantsAccess = $roleTeamParentId && $candidateParentId && $roleTeamParentId === $candidateParentId;
                }

                if (!$grantsAccess) {
                    continue;
                }

                $rolesByTeam[$candidateId] ??= [];
                $role = [
                    'id' => $teamRole->role,
                    'label' => $this->roleName($teamRole->role),
                    'isCurrent' => currentTeamRole()
                        && currentTeamRole()->team_id == $candidateId
                        && currentTeamRole()->role == $teamRole->role,
                ];

                if ($this->shouldUseSwitchRole($rolesByTeam[$candidateId]['switchRole'] ?? null, $role)) {
                    $rolesByTeam[$candidateId]['switchRole'] = $role;
                }

                if ($this->hasSelectableAncestorRole($candidateAncestors, $selectableRoleTeamIdsByRole->get($teamRole->role))) {
                    continue;
                }

                $rolesByTeam[$candidateId]['roles'][$teamRole->role] = $role;
            }
        }

        return collect($rolesByTeam)
            ->map(fn($access) => [
                'roles' => array_values($access['roles'] ?? []),
                'switchRole' => $access['switchRole'] ?? null,
            ])
            ->all();
    }

    private function activeTeamRoles($user, int|string|null $profile): Collection
    {
        $teamSelect = ['teams.id', 'teams.parent_team_id'];

        if ($this->hasCommitteeColumn()) {
            $teamSelect[] = 'teams.is_committee';
        }

        return $this->cache->remember(
            'teamRoleSwitcher.activeTeamRoles.v2.' . $user->id . '.' . ($profile ?: 'all'),
            CacheKeyBuilder::USER_ACTIVE_TEAM_ROLES,
            fn() => TeamRole::withoutGlobalScope('authUserHasPermissions')
                ->select(['team_roles.id', 'team_roles.user_id', 'team_roles.team_id', 'team_roles.role', 'team_roles.role_hierarchy'])
                ->where('team_roles.user_id', $user->id)
                ->whereHas('team')
                ->whereHas('roleRelation', fn($query) => $query->when($profile, fn($query) => $query->where('profile', $profile)))
                ->with([
                    'team' => fn($query) => $query->select($teamSelect),
                    'roleRelation' => fn($query) => $query->select(['roles.id', 'roles.name', 'roles.profile']),
                ])
                ->get(),
            (int) config('kompo-auth.cache.role_switcher_ttl', 900)
        );
    }

    private function childrenBaseQuery(string $mode, ?int $parentId, $user = null, int|string|null $profile = null): Builder
    {
        $query = $this->teamQuery();

        if ($parentId) {
            $query->where('teams.parent_team_id', $parentId);
        } else {
            $query->whereNull('teams.parent_team_id');
        }

        $this->applyModeFilter($query, $mode);

        return $query->orderBy('teams.team_name')->orderBy('teams.id');
    }

    private function searchTeams(string $mode, string $search, int $limit, int $offset = 0): Collection
    {
        $query = $this->teamQuery()
            ->with('parentTeam:id,team_name')
            ->where(function ($query) use ($search, $mode) {
                $query->where('teams.team_name', 'LIKE', wildcardSpace($search));

                if ($mode === TeamAccessHierarchyBuilder::MODE_COMMITTEES) {
                    $query->orWhereExists(function ($query) use ($search) {
                        $query->selectRaw(1)
                            ->from('teams as parent_teams')
                            ->whereColumn('parent_teams.id', 'teams.parent_team_id')
                            ->where('parent_teams.team_name', 'LIKE', wildcardSpace($search));
                    });
                }
            });

        $this->applyModeFilter($query, $mode, search: true);

        return $query->orderBy('teams.team_name')->orderBy('teams.id')->skip($offset)->take($limit)->get();
    }

    private function teamQuery(): Builder
    {
        $select = ['teams.id', 'teams.team_name', 'teams.parent_team_id'];

        if ($this->hasTeamLevelColumn()) {
            $select[] = 'teams.team_level';
        }

        if ($this->hasCommitteeColumn()) {
            $select[] = 'teams.is_committee';
        }

        return TeamModel::withoutGlobalScope('authUserHasPermissions')->select($select);
    }

    private function applyModeFilter(Builder $query, string $mode, bool $search = false): void
    {
        if (!$this->hasCommitteeColumn()) {
            return;
        }

        if ($mode === TeamAccessHierarchyBuilder::MODE_COMMITTEES) {
            if ($search) {
                $query->where('teams.is_committee', 1);
            }

            return;
        }

        $query->where(function ($query) {
            $query->where('teams.is_committee', 0)
                ->orWhereNull('teams.is_committee');
        });
    }

    private function ancestorIdsByCandidate(array $candidateIds): Collection
    {
        $candidateIds = collect($candidateIds)->filter()->unique()->values()->all();

        if (empty($candidateIds)) {
            return collect();
        }

        $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));

        $sql = "
            WITH RECURSIVE candidate_ancestors AS (
                SELECT id as target_team_id, id, parent_team_id, 0 as depth
                FROM teams
                WHERE id IN ({$placeholders})

                UNION ALL

                SELECT ca.target_team_id, t.id, t.parent_team_id, ca.depth + 1
                FROM teams t
                INNER JOIN candidate_ancestors ca ON t.id = ca.parent_team_id
                WHERE ca.depth < " . self::MAX_PARENT_DEPTH . "
                  AND t.deleted_at IS NULL
            )
            SELECT target_team_id, id
            FROM candidate_ancestors
            WHERE id != target_team_id
        ";

        return collect(DB::select($sql, $candidateIds))
            ->groupBy('target_team_id')
            ->map(fn($rows) => $rows->pluck('id')->map(fn($id) => (int) $id)->values());
    }

    private function currentPathIds(): array
    {
        $currentTeamId = currentTeamId();

        if (!$currentTeamId) {
            return [];
        }

        return app(TeamHierarchyInterface::class)
            ->getAncestorTeamIds($currentTeamId)
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();
    }

    private function isVisibleNode(array $node, string $mode): bool
    {
        if ($node['isSelectable']) {
            return $this->hasSwitchableRole($node) || $node['hasChildren'];
        }

        return $node['hasChildren'];
    }

    private function hasSwitchableRole(array $node): bool
    {
        return !empty($node['roles']) || !empty($node['switchRole']);
    }

    private function countDirectChildren(int $teamId, string $mode): int
    {
        $query = $this->teamQuery()->where('teams.parent_team_id', $teamId);
        $this->applyModeFilter($query, $mode);

        return $query->count();
    }

    private function visibleDirectChildrenCounts(Collection $parentIds, $user, int|string|null $profile, string $mode): array
    {
        $parentIds = $parentIds
            ->map(fn($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($parentIds->isEmpty()) {
            return [];
        }

        $query = $this->teamQuery()->whereIn('teams.parent_team_id', $parentIds->all());
        $this->applyModeFilter($query, $mode);

        $children = $query->get();

        if ($children->isEmpty()) {
            return [];
        }

        $accessByChild = $this->resolveRolesForCandidates($children, $user, $profile, $mode);
        $activeRoleBranchIndex = $this->activeRoleBranchIndex($user, $profile, $mode);
        $counts = [];

        foreach ($children as $child) {
            $childId = (int) $child->id;
            $parentId = $child->parent_team_id ? (int) $child->parent_team_id : null;

            if (!$parentId) {
                continue;
            }

            $childAccess = $this->isSelectableForMode($child, $mode) ? ($accessByChild[$childId] ?? []) : [];

            if (empty($childAccess['roles']) && empty($childAccess['switchRole']) && !$activeRoleBranchIndex->has($childId)) {
                continue;
            }

            $counts[$parentId] = ($counts[$parentId] ?? 0) + 1;
        }

        return $counts;
    }

    private function countDirectCommittees(int $teamId): int
    {
        if (!$this->hasCommitteeColumn()) {
            return 0;
        }

        return $this->teamQuery()
            ->where('teams.parent_team_id', $teamId)
            ->where('teams.is_committee', 1)
            ->count();
    }

    private function roleName(string $roleId): string
    {
        if (!array_key_exists($roleId, $this->roleNameCache)) {
            $role = RoleModel::withoutGlobalScope('authUserHasPermissions')->find($roleId);
            $this->roleNameCache[$roleId] = $role?->name ?: $roleId;
        }

        return $this->roleNameCache[$roleId];
    }

    private function hasSelectableAncestorRole(Collection $candidateAncestors, ?Collection $roleTeamIds): bool
    {
        if (!$roleTeamIds || $roleTeamIds->isEmpty() || $candidateAncestors->isEmpty()) {
            return false;
        }

        return $candidateAncestors->intersectByKeys($roleTeamIds)->isNotEmpty();
    }

    private function shouldUseSwitchRole(?array $currentRole, array $candidateRole): bool
    {
        return !$currentRole || (!($currentRole['isCurrent'] ?? false) && ($candidateRole['isCurrent'] ?? false));
    }

    private function activeRoleBranchIndex($user, int|string|null $profile, string $mode): Collection
    {
        return $this->cache->rememberRequest(
            'teamRoleSwitcher.activeRoleBranchIndex.' . $user->id . '.' . ($profile ?: 'all') . '.' . $mode,
            function () use ($user, $profile, $mode) {
                $roleTeamIds = $this->activeTeamRoles($user, $profile)
                    ->filter(fn($teamRole) => $teamRole->team && $this->isSelectableForMode($teamRole->team, $mode))
                    ->pluck('team_id')
                    ->map(fn($id) => (int) $id)
                    ->filter()
                    ->unique()
                    ->values();

                if ($roleTeamIds->isEmpty()) {
                    return collect();
                }

                $branchIds = $roleTeamIds;

                foreach ($this->ancestorIdsByCandidate($roleTeamIds->all()) as $ancestorIds) {
                    $branchIds = $branchIds->concat($ancestorIds);
                }

                return $branchIds->unique()->flip();
            }
        );
    }

    private function teamLevelValue($team): ?int
    {
        if (!$this->hasTeamLevelColumn() || $team->team_level === null) {
            return null;
        }

        if (is_object($team->team_level) && isset($team->team_level->value)) {
            return (int) $team->team_level->value;
        }

        return (int) $team->team_level;
    }

    private function teamLevelLabel($team, bool $isCommittee): string
    {
        if ($isCommittee) {
            return __('auth.switcher-committee-short');
        }

        $level = $team->team_level ?? null;

        if (is_object($level) && method_exists($level, 'label')) {
            return $level->label();
        }

        return match ($this->teamLevelValue($team)) {
            1 => __('auth.switcher-national'),
            2 => __('auth.switcher-district'),
            3 => __('auth.switcher-group'),
            4 => __('auth.switcher-unit'),
            default => __('auth.switcher-team'),
        };
    }

    private function teamLevelKey($team, bool $isCommittee): string
    {
        if ($isCommittee) {
            return 'committee';
        }

        return match ($this->teamLevelValue($team)) {
            1 => 'national',
            2 => 'district',
            3 => 'group',
            4 => 'unit',
            default => 'team',
        };
    }

    private function isCommittee($team): bool
    {
        return $this->hasCommitteeColumn() && (bool) ($team->is_committee ?? false);
    }

    private function isSelectableForMode($team, string $mode): bool
    {
        $isCommittee = $this->isCommittee($team);

        return $mode === TeamAccessHierarchyBuilder::MODE_COMMITTEES ? $isCommittee : !$isCommittee;
    }

    private function emptyPayload(string $mode, bool $includeRoot = true): LazyHierarchyPayload
    {
        return LazyHierarchyPayload::make(['mode' => $mode], self::ROOT_KEY, $includeRoot);
    }

    private function normalizeMode(?string $mode): string
    {
        return $mode === TeamAccessHierarchyBuilder::MODE_COMMITTEES
            ? TeamAccessHierarchyBuilder::MODE_COMMITTEES
            : TeamAccessHierarchyBuilder::MODE_TEAMS;
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit ?: self::DEFAULT_LIMIT));
    }

    private function parentKey(?int $parentId): string
    {
        return $parentId ? $this->nodeId($parentId) : self::ROOT_KEY;
    }

    private function nodeId(int $teamId): string
    {
        return 'team-' . $teamId;
    }

    private function hasCommitteeColumn(): bool
    {
        return hasColumnCached('teams', 'is_committee');
    }

    private function hasTeamLevelColumn(): bool
    {
        return hasColumnCached('teams', 'team_level');
    }
}
