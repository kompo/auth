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
        $currentTeamRole = function_exists('currentTeamRole') ? currentTeamRole() : null;

        $pageScopes = $resolvedScopes->take($limit)->values();
        $this->appendRootScopesPage(
            payload: $payload,
            scopes: $pageScopes,
            mode: $mode,
            limit: $limit,
            total: $resolvedScopes->count(),
            offset: 0,
            append: false,
            currentTeamRole: $currentTeamRole,
        );

        $nodeBudget = max(0, $lookaheadBudget - $pageScopes->count());

        $currentScope = $this->currentScope($resolvedScopes, $currentTeamRole);

        if ($currentScope) {
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
        $currentTeamRole = function_exists('currentTeamRole') ? currentTeamRole() : null;

        if ($parentNodeId === null) {
            $offset = max(0, (int) ($cursor ?? 0));
            $pageScopes = $resolvedScopes->slice($offset, $limit)->values();

            $this->appendRootScopesPage(
                payload: $payload,
                scopes: $pageScopes,
                mode: $mode,
                limit: $limit,
                total: $resolvedScopes->count(),
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

            $ancestorIdsByTeam = $this->hierarchy->getBatchAncestorTeamIdsByTarget(
                $teams->pluck('id')->map(fn($id) => (int) $id)->all()
            );

            foreach ($teams as $team) {
                $teamId = (int) $team->id;

                foreach ($scopesByTeamId[$teamId] ?? [] as $scope) {
                    $ctx = $this->decorateTeam(
                        scope: $scope,
                        team: $team,
                        mode: $mode,
                        currentPathIds: $currentPathByScope[$scope->key] ?? [],
                        currentTeamRole: $currentTeamRole,
                        ancestorIds: $ancestorIdsByTeam->get($teamId, collect()),
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

    private function appendRootScopesPage(
        LazyHierarchyPayload $payload,
        Collection $scopes,
        string $mode,
        int $limit,
        int $total,
        int $offset,
        bool $append,
        $currentTeamRole,
    ): void {
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

        $payload
            ->setChildren(self::ROOT_KEY, $nodeIds, $append)
            ->setPaging(self::ROOT_KEY, $nextCursor < $total ? $nextCursor : null, $total, $limit, $append);
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
        $ancestorIdsByTeam = $this->hierarchy->getBatchAncestorTeamIdsByTarget(
            $teams->pluck('id')->map(fn($id) => (int) $id)->all()
        );
        $contexts = $teams
            ->map(fn($team) => $this->decorateTeam(
                $scope,
                $team,
                $mode,
                $currentPathIds,
                $currentTeamRole,
                $ancestorIdsByTeam->get((int) $team->id, collect()),
            ))
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

        $teamsById = $this->teams->findMany($currentPathIds)->keyBy('id');
        $ancestorIdsByTeam = $this->hierarchy->getBatchAncestorTeamIdsByTarget($currentPathIds);

        if ($teamsById->isEmpty()) {
            return;
        }

        $contextsByTeamId = $teamsById
            ->map(fn($team) => $this->decorateTeam(
                $scope,
                $team,
                $mode,
                $currentPathIds,
                $currentTeamRole,
                $ancestorIdsByTeam->get((int) $team->id, collect()),
            ))
            ->keyBy(fn(HierarchyNodeContext $ctx) => $ctx->teamId);

        foreach ($currentPathIds as $index => $teamId) {
            $ctx = $contextsByTeamId->get($teamId);

            if (!$ctx) {
                continue;
            }

            $node = $this->nodes->toPayload($ctx, $this->switchUrl);
            $payload->addNode($node);

            $parentNodeKey = $index === 0
                ? self::ROOT_KEY
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
        $ancestorIds = null,
    ): HierarchyNodeContext {
        $teamId = (int) $team->id;
        $roles = [];
        $switchRole = null;
        $hasAncestorSwitchableRole = collect($ancestorIds ?: [])
            ->contains(fn($ancestorId) => $scope->containsSwitchableTeam((int) $ancestorId));

        if ($scope->containsSwitchableTeam($teamId)) {
            $role = [
                'id' => $scope->roleId,
                'label' => $scope->roleLabel,
                'isCurrent' => $currentTeamRole
                    && (int) $currentTeamRole->team_id === $teamId
                    && (string) $currentTeamRole->role === $scope->roleId,
            ];

            if ($hasAncestorSwitchableRole) {
                $switchRole = $role;
            } else {
                $roles[] = $role;
            }
        }

        return $this->nodes->context(
            scopeKey: $scope->key,
            team: $team,
            access: [
                'roles' => $roles,
                'switchRole' => $switchRole,
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
