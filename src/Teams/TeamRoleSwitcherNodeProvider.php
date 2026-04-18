<?php

namespace Kompo\Auth\Teams;

use Condoedge\Utils\Kompo\LazyHierarchy\LazyHierarchyPayload;
use Illuminate\Support\Collection;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;
use Kompo\Auth\Teams\Contracts\TeamRoleAccessResolverInterface;

class TeamRoleSwitcherNodeProvider
{
    private const ROOT_KEY = LazyHierarchyPayload::ROOT_KEY;
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;
    private const LOOKAHEAD_DEPTH = 1;
    private const DEFAULT_LOOKAHEAD_BUDGET = 80;
    private const MAX_SCAN_PAGES = 6;
    private const MAX_SEARCH_SCAN_PAGES = 30;

    public function __construct(
        private TeamRoleAccessResolverInterface $access,
        private TeamRoleSwitcherTeamRepository $teams,
        private TeamRoleSwitcherNodeFactory $nodes,
        private TeamHierarchyInterface $hierarchy,
    ) {}

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
            $teams = $this->teams->search($mode, $search, $take, $offset);

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
        $total = $this->teams->childrenTotal($mode, $parentId);

        while ($visible->count() < $limit && $scanPages < self::MAX_SCAN_PAGES) {
            $take = max($limit * 2, self::DEFAULT_LIMIT);
            $rawTeams = $this->teams->children($mode, $parentId, $take, $scanOffset);

            if ($rawTeams->isEmpty()) {
                break;
            }

            $decorated = $this->decorateTeams($rawTeams, $user, $profile, $mode, $currentPathIds)
                ->filter(fn($node) => $this->isVisibleNode($node));

            $visible = $visible->concat($decorated)->take($limit)->values();
            $scanOffset += $rawTeams->count();
            $scanPages++;

            if ($rawTeams->count() < $take) {
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

        $teamsById = $this->teams->findMany($currentPathIds)->keyBy('id');

        if ($teamsById->isEmpty()) {
            return;
        }

        $currentTeam = $teamsById->get((int) end($currentPathIds));

        if (!$currentTeam || !$this->teams->isSelectableForMode($currentTeam, $mode)) {
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
            $payload->prependChild($this->parentKey($parentId), $node['id']);

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

        $currentTeamRole = function_exists('currentTeamRole') ? currentTeamRole() : null;
        $accessByTeam = $this->access->resolveForCandidates(
            $teams,
            $user,
            $profile,
            $mode,
            $currentTeamRole
        );
        $visibleChildrenCounts = $mode === TeamAccessHierarchyBuilder::MODE_TEAMS
            ? $this->visibleDirectChildrenCounts($teams->pluck('id'), $user, $profile, $mode)
            : [];

        return $teams->map(function ($team) use ($accessByTeam, $visibleChildrenCounts, $mode, $currentPathIds, $currentTeamRole) {
            $teamId = (int) $team->id;
            $isSelectable = $this->teams->isSelectableForMode($team, $mode);
            $access = $isSelectable ? ($accessByTeam[$teamId] ?? []) : [];
            $childrenCount = $mode === TeamAccessHierarchyBuilder::MODE_TEAMS
                ? (int) ($visibleChildrenCounts[$teamId] ?? 0)
                : $this->teams->countDirectChildren($teamId, $mode);
            $committeeCount = $mode === TeamAccessHierarchyBuilder::MODE_COMMITTEES
                ? $this->teams->countDirectCommittees($teamId)
                : 0;

            return $this->nodes->make(
                team: $team,
                access: $access,
                mode: $mode,
                currentPathIds: $currentPathIds,
                childrenCount: $childrenCount,
                committeeCount: $committeeCount,
                currentTeamRole: $currentTeamRole,
            );
        })->values();
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

        $query = $this->teams->teamQuery()->whereIn('teams.parent_team_id', $parentIds->all());
        $this->teams->applyModeFilter($query, $mode);
        $children = $query->get();

        if ($children->isEmpty()) {
            return [];
        }

        $accessByChild = $this->access->resolveForCandidates(
            $children,
            $user,
            $profile,
            $mode,
            includeCurrentRole: false
        );
        $activeRoleBranchIndex = $this->access->activeRoleBranchIndex($user, $profile, $mode);
        $counts = [];

        foreach ($children as $child) {
            $childId = (int) $child->id;
            $parentId = $child->parent_team_id ? (int) $child->parent_team_id : null;

            if (!$parentId) {
                continue;
            }

            $childAccess = $this->teams->isSelectableForMode($child, $mode) ? ($accessByChild[$childId] ?? []) : [];

            if (empty($childAccess['roles']) && empty($childAccess['switchRole']) && !$activeRoleBranchIndex->has($childId)) {
                continue;
            }

            $counts[$parentId] = ($counts[$parentId] ?? 0) + 1;
        }

        return $counts;
    }

    private function currentPathIds(): array
    {
        $currentTeamId = currentTeamId();

        if (!$currentTeamId) {
            return [];
        }

        return $this->hierarchy
            ->getAncestorTeamIds($currentTeamId)
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();
    }

    private function isVisibleNode(array $node): bool
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
        return $parentId ? $this->nodes->nodeId($parentId) : self::ROOT_KEY;
    }
}
