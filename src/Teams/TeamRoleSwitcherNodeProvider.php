<?php

namespace Kompo\Auth\Teams;

use Condoedge\Utils\Kompo\LazyHierarchy\LazyHierarchyPayload;
use Illuminate\Support\Collection;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;

class TeamRoleSwitcherNodeProvider
{
    private const ROOT_KEY = LazyHierarchyPayload::ROOT_KEY;
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;
    private const LOOKAHEAD_DEPTH = 1;
    private const DEFAULT_LOOKAHEAD_BUDGET = 80;
    private const MAX_SEARCH_SCAN_PAGES = 30;

    public function __construct(
        private TeamRoleSwitcherScopeResolver $scopes,
        private TeamRoleSwitcherTeamRepository $teams,
        private TeamRoleSwitcherNodeFactory $nodes,
        private TeamHierarchyInterface $hierarchy,
        private TeamRoleSwitcherScopeCodec $codec,
        private string $switchUrl,
    ) {}

    public function bootstrap($user, int|string|null $profile, string $mode, int $limit, int $lookaheadBudget): array
    {
        $mode = $this->normalizeMode($mode);
        $limit = $this->normalizeLimit($limit);
        $lookaheadBudget = max($limit, min(120, $lookaheadBudget ?: self::DEFAULT_LOOKAHEAD_BUDGET));
        $payload = $this->emptyPayload($mode);
        $resolvedScopes = $this->scopes->resolve($user, $profile, $mode);
        $roleGroups = $this->roleGroups($resolvedScopes);
        $currentTeamRole = function_exists('currentTeamRole') ? currentTeamRole() : null;

        $pageRoleGroups = $roleGroups->take($limit)->values();
        $this->appendRootRoleGroupsPage(
            payload: $payload,
            roleGroups: $pageRoleGroups,
            limit: $limit,
            total: $roleGroups->count(),
            offset: 0,
            append: false,
            currentTeamRole: $currentTeamRole,
        );

        $nodeBudget = max(0, $lookaheadBudget - $pageRoleGroups->count());

        $currentScope = $this->currentScope($resolvedScopes, $currentTeamRole);

        if ($currentScope) {
            $currentRoleGroup = $this->findRoleGroup($roleGroups, $currentScope->roleId);

            if ($currentRoleGroup) {
                $this->appendRoleScopesPage(
                    payload: $payload,
                    roleGroup: $currentRoleGroup,
                    mode: $mode,
                    limit: $limit,
                    offset: 0,
                    append: false,
                    currentTeamRole: $currentTeamRole,
                );

                $roleNode = $this->nodes->rolePayload(
                    $currentRoleGroup['roleId'],
                    $currentRoleGroup['roleLabel'],
                    $currentRoleGroup['scopes']->count(),
                    (string) ($currentTeamRole->role ?? '') === $currentRoleGroup['roleId'],
                );

                $payload
                    ->addNode($roleNode)
                    ->prependChild(self::ROOT_KEY, $roleNode['id'])
                    ->expand($roleNode['id']);
            }

            $this->appendCurrentScopePath(
                payload: $payload,
                scope: $currentScope,
                mode: $mode,
                limit: $limit,
                currentTeamRole: $currentTeamRole,
                nodeBudget: $nodeBudget,
            );
        }

        return $payload->toArray();
    }

    public function children(
        $user,
        int|string|null $profile,
        string $mode,
        ?string $parentNodeId,
        int $limit,
        ?int $cursor,
        int $lookaheadBudget,
    ): array {
        $mode = $this->normalizeMode($mode);
        $limit = $this->normalizeLimit($limit);
        $nodeBudget = max($limit, min(120, $lookaheadBudget ?: self::DEFAULT_LOOKAHEAD_BUDGET));
        $payload = $this->emptyPayload($mode, $parentNodeId === null);
        $resolvedScopes = $this->scopes->resolve($user, $profile, $mode);
        $roleGroups = $this->roleGroups($resolvedScopes);
        $currentTeamRole = function_exists('currentTeamRole') ? currentTeamRole() : null;

        if ($parentNodeId === null) {
            $offset = max(0, (int) ($cursor ?? 0));
            $pageRoleGroups = $roleGroups->slice($offset, $limit)->values();

            $this->appendRootRoleGroupsPage(
                payload: $payload,
                roleGroups: $pageRoleGroups,
                limit: $limit,
                total: $roleGroups->count(),
                offset: $offset,
                append: $offset > 0,
                currentTeamRole: $currentTeamRole,
            );

            return $payload->toArray();
        }

        $parsedRole = $this->codec->parseRoleNodeId($parentNodeId);

        if ($parsedRole) {
            $roleGroup = $this->findRoleGroup($roleGroups, $parsedRole['roleId']);

            if (!$roleGroup) {
                return $payload->toArray();
            }

            $offset = max(0, (int) ($cursor ?? 0));

            $this->appendRoleScopesPage(
                payload: $payload,
                roleGroup: $roleGroup,
                mode: $mode,
                limit: $limit,
                offset: $offset,
                append: $offset > 0,
                currentTeamRole: $currentTeamRole,
            );

            return $payload->toArray();
        }

        if ($parentNodeId !== null && ctype_digit($parentNodeId)) {
            $legacyParentTeamId = (int) $parentNodeId;
            $matchingScopes = $this->legacyScopesForParentTeam($resolvedScopes, $legacyParentTeamId, $currentTeamRole);

            if ($matchingScopes->isEmpty()) {
                return $payload->toArray();
            }

            $this->appendLegacyScopedLevel(
                payload: $payload,
                scopes: $matchingScopes,
                mode: $mode,
                parentTeamId: $legacyParentTeamId,
                limit: $limit,
                cursor: $cursor,
                append: (int) ($cursor ?? 0) > 0,
                rawParentNodeKey: $parentNodeId,
                currentTeamRole: $currentTeamRole,
            );

            return $payload->toArray();
        }

        $parsed = $this->codec->parseNodeId($parentNodeId);

        if (!$parsed) {
            return $payload->toArray();
        }

        $scope = $resolvedScopes->first(
            fn(TeamRoleSwitcherScope $scope) => $scope->key === $parsed['scopeKey']
        );

        if (!$scope || !$scope->containsTeam($parsed['teamId'])) {
            return $payload->toArray();
        }

        $this->appendScopedLevel(
            payload: $payload,
            scope: $scope,
            mode: $mode,
            parentTeamId: $parsed['teamId'],
            limit: $limit,
            cursor: $cursor,
            lookaheadDepth: self::LOOKAHEAD_DEPTH,
            nodeBudget: $nodeBudget,
            currentPathIds: $this->currentPathIdsForScope($scope, $currentTeamRole),
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

        $resolvedScopes = $this->scopes->resolve($user, $profile, $mode);

        if ($resolvedScopes->isEmpty()) {
            return $payload->toArray();
        }

        $currentTeamRole = function_exists('currentTeamRole') ? currentTeamRole() : null;
        $currentPathByScope = $resolvedScopes->mapWithKeys(
            fn(TeamRoleSwitcherScope $scope) => [$scope->key => $this->currentPathIdsForScope($scope, $currentTeamRole)]
        );
        $scopesByTeamId = [];

        foreach ($resolvedScopes as $scope) {
            foreach ($scope->teamIds() as $teamId) {
                $scopesByTeamId[$teamId] ??= [];
                $scopesByTeamId[$teamId][] = $scope;
            }
        }

        $contexts = collect();
        $seenNodeIds = [];
        $offset = 0;
        $scanPages = 0;
        $take = max($limit * 3, self::MAX_LIMIT);

        while ($contexts->count() < $limit && $scanPages < self::MAX_SEARCH_SCAN_PAGES) {
            $teams = $this->teams->search($mode, $search, $take, $offset);

            if ($teams->isEmpty()) {
                break;
            }

            foreach ($teams as $team) {
                $teamId = (int) $team->id;

                foreach ($scopesByTeamId[$teamId] ?? [] as $scope) {
                    $ctx = $this->decorateTeam(
                        scope: $scope,
                        team: $team,
                        mode: $mode,
                        currentPathIds: $currentPathByScope[$scope->key] ?? [],
                        currentTeamRole: $currentTeamRole,
                    );

                    if (!$ctx->hasSwitchableRole() || isset($seenNodeIds[$ctx->id])) {
                        continue;
                    }

                    $seenNodeIds[$ctx->id] = true;
                    $contexts->push($ctx);

                    if ($contexts->count() >= $limit) {
                        break 2;
                    }
                }
            }

            $offset += $teams->count();
            $scanPages++;

            if ($teams->count() < $take) {
                break;
            }
        }

        foreach ($contexts as $ctx) {
            $node = $this->nodes->toPayload($ctx, $this->switchUrl);
            $payload->addNode($node)->appendChild(self::ROOT_KEY, $node['id']);
        }

        $payload->setPaging(self::ROOT_KEY, null, $contexts->count(), $limit);

        return $payload->toArray();
    }

    private function appendRootRoleGroupsPage(
        LazyHierarchyPayload $payload,
        Collection $roleGroups,
        int $limit,
        int $total,
        int $offset,
        bool $append,
        $currentTeamRole,
    ): void {
        $nodeIds = [];
        $currentRoleId = (string) ($currentTeamRole->role ?? '');

        foreach ($roleGroups as $roleGroup) {
            $node = $this->nodes->rolePayload(
                $roleGroup['roleId'],
                $roleGroup['roleLabel'],
                $roleGroup['scopes']->count(),
                $currentRoleId === $roleGroup['roleId'],
            );
            $payload->addNode($node);
            $nodeIds[] = $node['id'];
        }

        $nextCursor = $offset + $roleGroups->count();

        $payload
            ->setChildren(self::ROOT_KEY, $nodeIds, $append)
            ->setPaging(self::ROOT_KEY, $nextCursor < $total ? $nextCursor : null, $total, $limit, $append);
    }

    private function appendRoleScopesPage(
        LazyHierarchyPayload $payload,
        array $roleGroup,
        string $mode,
        int $limit,
        int $offset,
        bool $append,
        $currentTeamRole,
    ): void {
        $scopes = $roleGroup['scopes']->slice($offset, $limit)->values();
        $nodeIds = [];

        foreach ($scopes as $scope) {
            $ctx = $this->decorateTeam(
                scope: $scope,
                team: $scope->rootTeam,
                mode: $mode,
                currentPathIds: $this->currentPathIdsForScope($scope, $currentTeamRole),
                currentTeamRole: $currentTeamRole,
            );
            $node = $this->nodes->toPayload($ctx, $this->switchUrl);
            $payload->addNode($node);
            $nodeIds[] = $node['id'];
        }

        $nextCursor = $offset + $scopes->count();
        $roleNodeId = $this->codec->roleNodeId($roleGroup['roleId']);

        $payload
            ->setChildren($roleNodeId, $nodeIds, $append)
            ->setPaging(
                $roleNodeId,
                $nextCursor < $roleGroup['scopes']->count() ? $nextCursor : null,
                $roleGroup['scopes']->count(),
                $limit,
                $append
            );
    }

    private function appendScopedLevel(
        LazyHierarchyPayload $payload,
        TeamRoleSwitcherScope $scope,
        string $mode,
        int $parentTeamId,
        int $limit,
        ?int $cursor,
        int $lookaheadDepth,
        int &$nodeBudget,
        array $currentPathIds,
        bool $append = false,
        ?string $parentNodeKey = null,
    ): void {
        if ($nodeBudget <= 0) {
            return;
        }

        $page = $this->visibleChildrenPage($scope, $mode, $parentTeamId, $limit, $cursor, $currentPathIds);
        $nodeIds = [];

        foreach ($page['contexts'] as $ctx) {
            if ($nodeBudget <= 0) {
                break;
            }

            $node = $this->nodes->toPayload($ctx, $this->switchUrl);
            $payload->addNode($node);
            $nodeIds[] = $node['id'];
            $nodeBudget--;
        }

        $parentKey = $parentNodeKey ?: $this->nodes->nodeId($scope->key, $parentTeamId);

        $payload
            ->setChildren($parentKey, $nodeIds, $append)
            ->setPaging(
                $parentKey,
                $page['nextCursor'],
                $page['total'],
                $limit,
                $append
            );

        if ($lookaheadDepth <= 0) {
            return;
        }

        foreach ($page['contexts'] as $ctx) {
            if ($nodeBudget <= 0 || !$ctx->hasChildren) {
                continue;
            }

            $this->appendScopedLevel(
                payload: $payload,
                scope: $scope,
                mode: $mode,
                parentTeamId: $ctx->teamId,
                limit: $limit,
                cursor: null,
                lookaheadDepth: $lookaheadDepth - 1,
                nodeBudget: $nodeBudget,
                currentPathIds: $currentPathIds,
            );
        }
    }

    private function visibleChildrenPage(
        TeamRoleSwitcherScope $scope,
        string $mode,
        int $parentTeamId,
        int $limit,
        ?int $cursor,
        array $currentPathIds,
    ): array {
        $offset = max(0, (int) ($cursor ?? 0));
        $teamIds = $scope->teamIds();
        $teams = $this->teams->childrenForIds($mode, $parentTeamId, $teamIds, $limit, $offset);
        $total = $this->teams->childrenForIdsTotal($mode, $parentTeamId, $teamIds);
        $currentTeamRole = function_exists('currentTeamRole') ? currentTeamRole() : null;
        $contexts = $teams
            ->map(fn($team) => $this->decorateTeam($scope, $team, $mode, $currentPathIds, $currentTeamRole))
            ->filter(fn(HierarchyNodeContext $ctx) => $ctx->isVisible())
            ->values();

        $nextCursor = $offset + $teams->count();

        return [
            'contexts' => $contexts,
            'nextCursor' => $nextCursor < $total ? $nextCursor : null,
            'total' => $total,
        ];
    }

    private function appendLegacyScopedLevel(
        LazyHierarchyPayload $payload,
        Collection $scopes,
        string $mode,
        int $parentTeamId,
        int $limit,
        ?int $cursor,
        bool $append,
        string $rawParentNodeKey,
        $currentTeamRole,
    ): void {
        $preferredScope = $scopes->first();

        if (!$preferredScope) {
            return;
        }

        $page = $this->visibleChildrenPage(
            $preferredScope,
            $mode,
            $parentTeamId,
            $limit,
            $cursor,
            $this->currentPathIdsForScope($preferredScope, $currentTeamRole),
        );
        $nodeIds = [];

        foreach ($page['contexts'] as $ctx) {
            $node = $this->nodes->toPayload($ctx, $this->switchUrl);
            $payload->addNode($node);
            $nodeIds[] = $node['id'];
        }

        $parentKeys = collect([$rawParentNodeKey])
            ->concat($scopes->map(fn(TeamRoleSwitcherScope $scope) => $this->nodes->nodeId($scope->key, $parentTeamId)))
            ->unique()
            ->values()
            ->all();

        foreach ($parentKeys as $parentKey) {
            $payload
                ->setChildren($parentKey, $nodeIds, $append)
                ->setPaging($parentKey, $page['nextCursor'], $page['total'], $limit, $append);
        }
    }

    private function appendCurrentScopePath(
        LazyHierarchyPayload $payload,
        TeamRoleSwitcherScope $scope,
        string $mode,
        int $limit,
        $currentTeamRole,
        int &$nodeBudget,
    ): void {
        $currentPathIds = $this->currentPathIdsForScope($scope, $currentTeamRole);

        if (empty($currentPathIds)) {
            return;
        }

        $roleNodeId = $this->codec->roleNodeId($scope->roleId);
        $payload->expand($roleNodeId);
        $teamsById = $this->teams->findMany($currentPathIds)->keyBy('id');

        if ($teamsById->isEmpty()) {
            return;
        }

        $contextsByTeamId = $teamsById
            ->map(fn($team) => $this->decorateTeam($scope, $team, $mode, $currentPathIds, $currentTeamRole))
            ->keyBy(fn(HierarchyNodeContext $ctx) => $ctx->teamId);

        foreach ($currentPathIds as $index => $teamId) {
            $ctx = $contextsByTeamId->get($teamId);

            if (!$ctx) {
                continue;
            }

            $node = $this->nodes->toPayload($ctx, $this->switchUrl);
            $payload->addNode($node);

            $parentNodeKey = $index === 0
                ? $roleNodeId
                : $this->nodes->nodeId($scope->key, (int) $currentPathIds[$index - 1]);

            $payload->prependChild($parentNodeKey, $node['id']);

            if ($index < count($currentPathIds) - 1) {
                $payload->expand($node['id']);
                $this->appendScopedLevel(
                    payload: $payload,
                    scope: $scope,
                    mode: $mode,
                    parentTeamId: $teamId,
                    limit: $limit,
                    cursor: null,
                    lookaheadDepth: 0,
                    nodeBudget: $nodeBudget,
                    currentPathIds: $currentPathIds,
                );
            }
        }
    }

    private function decorateTeam(
        TeamRoleSwitcherScope $scope,
        $team,
        string $mode,
        array $currentPathIds,
        $currentTeamRole,
    ): HierarchyNodeContext {
        $teamId = (int) $team->id;
        $roles = [];

        if ($scope->containsSwitchableTeam($teamId)) {
            $roles[] = [
                'id' => $scope->roleId,
                'label' => $scope->roleLabel,
                'isCurrent' => $currentTeamRole
                    && (int) $currentTeamRole->team_id === $teamId
                    && (string) $currentTeamRole->role === $scope->roleId,
            ];
        }

        return $this->nodes->context(
            scopeKey: $scope->key,
            team: $team,
            access: [
                'roles' => $roles,
                'switchRole' => null,
            ],
            mode: $mode,
            currentPathIds: $currentPathIds,
            childrenCount: $this->teams->childrenForIdsTotal($mode, $teamId, $scope->teamIds()),
            committeeCount: 0,
            currentTeamRole: $currentTeamRole,
        );
    }


    private function currentScope(Collection $scopes, $currentTeamRole): ?TeamRoleSwitcherScope
    {
        if (!$currentTeamRole) {
            return null;
        }

        $currentTeamId = (int) ($currentTeamRole->team_id ?? 0);
        $currentRoleId = (string) ($currentTeamRole->role ?? '');

        return $scopes->first(function (TeamRoleSwitcherScope $scope) use ($currentTeamId, $currentRoleId) {
            return $scope->roleId === $currentRoleId && $scope->containsSwitchableTeam($currentTeamId);
        });
    }

    private function currentPathIdsForScope(TeamRoleSwitcherScope $scope, $currentTeamRole): array
    {
        if (!$currentTeamRole || (string) $currentTeamRole->role !== $scope->roleId) {
            return [];
        }

        $currentTeamId = (int) ($currentTeamRole->team_id ?? 0);

        if (!$currentTeamId || !$scope->containsSwitchableTeam($currentTeamId)) {
            return [];
        }

        $path = $this->hierarchy->getAncestorTeamIds($currentTeamId)
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        $scopedPath = [];
        $insideScope = false;

        foreach ($path as $teamId) {
            if ($teamId === $scope->rootTeamId) {
                $insideScope = true;
            }

            if (!$insideScope || !$scope->containsTeam($teamId)) {
                continue;
            }

            $scopedPath[] = $teamId;
        }

        if (empty($scopedPath)) {
            return [$scope->rootTeamId];
        }

        return $scopedPath;
    }

    private function normalizeMode(?string $mode): string
    {
        return $mode === TeamAccessHierarchyBuilder::MODE_COMMITTEES
            ? TeamAccessHierarchyBuilder::MODE_COMMITTEES
            : TeamAccessHierarchyBuilder::MODE_TEAMS;
    }

    private function roleGroups(Collection $scopes): Collection
    {
        if ($scopes->isEmpty()) {
            return collect();
        }

        return $scopes
            ->groupBy(fn(TeamRoleSwitcherScope $scope) => $scope->roleId)
            ->map(function (Collection $roleScopes, string $roleId) {
                $roleScopes = $roleScopes->values();
                $firstScope = $roleScopes->first();

                return [
                    'roleId' => $roleId,
                    'roleLabel' => $firstScope->roleLabel,
                    'scopes' => $roleScopes,
                    'sortKey' => implode(':', [
                        str_pad((string) ($this->teams->teamLevelSortValue($firstScope->rootTeam) ?? 9999), 4, '0', STR_PAD_LEFT),
                        str_pad((string) $firstScope->rootDepth, 4, '0', STR_PAD_LEFT),
                        strtolower((string) $firstScope->roleLabel),
                        strtolower((string) ($firstScope->rootTeam->team_name ?? '')),
                    ]),
                ];
            })
            ->sortBy('sortKey')
            ->values();
    }

    private function findRoleGroup(Collection $roleGroups, string $roleId): ?array
    {
        $roleGroup = $roleGroups->first(fn(array $roleGroup) => $roleGroup['roleId'] === $roleId);

        return is_array($roleGroup) ? $roleGroup : null;
    }

    private function legacyScopesForParentTeam(
        Collection $scopes,
        int $parentTeamId,
        $currentTeamRole,
    ): Collection {
        $matchingScopes = $scopes
            ->filter(fn(TeamRoleSwitcherScope $scope) => $scope->containsTeam($parentTeamId))
            ->values();

        if ($matchingScopes->isEmpty()) {
            return collect();
        }

        if ($currentTeamRole) {
            $currentRoleId = (string) ($currentTeamRole->role ?? '');
            $currentTeamId = (int) ($currentTeamRole->team_id ?? 0);

            $preferredScope = $matchingScopes->first(function (TeamRoleSwitcherScope $scope) use ($currentRoleId, $currentTeamId) {
                if ($scope->roleId !== $currentRoleId) {
                    return false;
                }

                return $currentTeamId
                    ? $scope->containsSwitchableTeam($currentTeamId) || $scope->containsTeam($currentTeamId)
                    : true;
            });

            if ($preferredScope) {
                return $matchingScopes
                    ->sortByDesc(fn(TeamRoleSwitcherScope $scope) => $scope->key === $preferredScope->key)
                    ->values();
            }
        }

        return $matchingScopes
            ->sortBy(fn(TeamRoleSwitcherScope $scope) => implode(':', [
                str_pad((string) $scope->rootDepth, 4, '0', STR_PAD_LEFT),
                strtolower($scope->roleLabel),
                strtolower((string) ($scope->rootTeam->team_name ?? '')),
            ]))
            ->values();
    }


    private function emptyPayload(string $mode, bool $includeRoot = true): LazyHierarchyPayload
    {
        return LazyHierarchyPayload::make(['mode' => $mode], self::ROOT_KEY, $includeRoot);
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit ?: self::DEFAULT_LIMIT));
    }
}
