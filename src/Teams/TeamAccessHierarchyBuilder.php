<?php

namespace Kompo\Auth\Teams;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Facades\TeamModel;

class TeamAccessHierarchyBuilder
{
    public const MODE_TEAMS = 'teams';
    public const MODE_COMMITTEES = 'committees';

    private const MAX_PARENT_DEPTH = 50;

    public function build(
        array|Collection $teamIdsWithRoles,
        ?string $search = '',
        string $mode = self::MODE_TEAMS,
    ): Collection {
        $teamIdsWithRoles = $this->normalizeTeamIdsWithRoles($teamIdsWithRoles);

        if ($teamIdsWithRoles->isEmpty()) {
            return collect();
        }

        $mode = $this->normalizeMode($mode);

        if ($mode === self::MODE_COMMITTEES && !$this->hasCommitteeColumn()) {
            return collect();
        }

        $displayedTeams = $this->getDisplayedTeams($teamIdsWithRoles->keys(), $search, $mode);

        if ($displayedTeams->isEmpty()) {
            return collect();
        }

        $displayTeams = $mode === self::MODE_COMMITTEES
            ? $this->loadCommitteeTeamsWithParents($displayedTeams)
            : $this->loadTeamPageTeamsWithAncestors($displayedTeams, $teamIdsWithRoles);

        $displayedTeamIds = $displayedTeams->pluck('id')->map(fn($id) => (int) $id)->values();
        $childrenCounts = $mode === self::MODE_COMMITTEES
            ? $this->countCommitteesByParent($teamIdsWithRoles->keys(), $search, $displayedTeams->pluck('parent_team_id'))
            : collect();
        $roles = $this->loadRoles($displayTeams, $teamIdsWithRoles);
        $nodes = $this->makeNodes($displayTeams, $teamIdsWithRoles, $roles, $displayedTeamIds, $childrenCounts);

        return $mode === self::MODE_COMMITTEES
            ? $this->buildCommitteeRoots($nodes, $displayedTeamIds)
            : $this->buildTeamRoots($nodes);
    }

    private function normalizeTeamIdsWithRoles(array|Collection $teamIdsWithRoles): Collection
    {
        return collect($teamIdsWithRoles)
            ->mapWithKeys(function ($roles, $teamId) {
                $roleIds = collect(is_iterable($roles) ? $roles : [$roles])
                    ->filter()
                    ->unique()
                    ->values();

                return [(int) $teamId => $roleIds];
            })
            ->filter(fn($roles, $teamId) => $teamId && $roles->isNotEmpty());
    }

    private function getDisplayedTeams(
        Collection $teamIds,
        ?string $search,
        string $mode,
    ): Collection {
        $query = $this->teamQuery()->whereIn('teams.id', $teamIds->values()->all());

        $this->applyModeFilter($query, $mode);
        $this->applySearchFilter($query, $search, $mode);

        return $query->orderBy('teams.team_name')->orderBy('teams.id')->get();
    }

    private function applyModeFilter(Builder $query, string $mode): void
    {
        if (!$this->hasCommitteeColumn()) {
            return;
        }

        if ($mode === self::MODE_COMMITTEES) {
            $query->where('teams.is_committee', 1);

            return;
        }

        $query->where(function ($query) {
            $query->where('teams.is_committee', 0)
                ->orWhereNull('teams.is_committee');
        });
    }

    private function applySearchFilter(Builder $query, ?string $search, string $mode): void
    {
        $search = trim((string) $search);

        if ($search === '') {
            return;
        }

        $wildcardSearch = wildcardSpace($search);

        $query->where(function ($query) use ($wildcardSearch, $mode) {
            $query->where('teams.team_name', 'LIKE', $wildcardSearch);

            if ($mode === self::MODE_COMMITTEES) {
                $query->orWhereExists(function ($query) use ($wildcardSearch) {
                    $query->selectRaw(1)
                        ->from('teams as parent_teams')
                        ->whereColumn('parent_teams.id', 'teams.parent_team_id')
                        ->where('parent_teams.team_name', 'LIKE', $wildcardSearch);
                });
            }
        });
    }

    private function loadCommitteeTeamsWithParents(Collection $committeeTeams): Collection
    {
        $parentIds = $committeeTeams->pluck('parent_team_id')->filter()->unique()->values();

        $parents = $parentIds->isEmpty()
            ? collect()
            : $this->teamQuery()->whereIn('teams.id', $parentIds->all())->get();

        return $parents->concat($committeeTeams)->keyBy('id');
    }

    private function loadTeamPageTeamsWithAncestors(Collection $pageTeams, Collection $teamIdsWithRoles): Collection
    {
        $loaded = $pageTeams->keyBy('id');
        $pendingParentIds = $this->accessibleParentIds($pageTeams, $teamIdsWithRoles, $loaded);
        $depth = 0;

        while ($pendingParentIds->isNotEmpty() && $depth < self::MAX_PARENT_DEPTH) {
            $parents = $this->teamQuery()->whereIn('teams.id', $pendingParentIds->all())->get()->keyBy('id');

            foreach ($parents as $teamId => $parent) {
                $loaded->put($teamId, $parent);
            }

            $pendingParentIds = $this->accessibleParentIds($parents, $teamIdsWithRoles, $loaded);
            $depth++;
        }

        return $loaded;
    }

    private function accessibleParentIds(Collection $teams, Collection $teamIdsWithRoles, Collection $loaded): Collection
    {
        return $teams
            ->pluck('parent_team_id')
            ->filter(fn($parentId) => $parentId && $teamIdsWithRoles->has((int) $parentId) && !$loaded->has((int) $parentId))
            ->map(fn($parentId) => (int) $parentId)
            ->unique()
            ->values();
    }

    private function loadRoles(Collection $displayTeams, Collection $teamIdsWithRoles): Collection
    {
        $roleIds = $displayTeams
            ->keys()
            ->flatMap(fn($teamId) => $teamIdsWithRoles->get((int) $teamId, collect()))
            ->unique()
            ->values();

        if ($roleIds->isEmpty()) {
            return collect();
        }

        return RoleModel::withoutGlobalScope('authUserHasPermissions')
            ->whereIn('roles.id', $roleIds->all())
            ->get()
            ->keyBy('id');
    }

    private function countCommitteesByParent(Collection $teamIds, ?string $search, Collection $parentIds): Collection
    {
        $parentIds = $parentIds->filter()->unique()->values();

        if ($parentIds->isEmpty()) {
            return collect();
        }

        $query = $this->teamQuery()
            ->whereIn('teams.id', $teamIds->values()->all())
            ->whereIn('teams.parent_team_id', $parentIds->all());

        $this->applyModeFilter($query, self::MODE_COMMITTEES);
        $this->applySearchFilter($query, $search, self::MODE_COMMITTEES);

        return $query
            ->selectRaw('teams.parent_team_id, COUNT(*) as committees_count')
            ->groupBy('teams.parent_team_id')
            ->pluck('committees_count', 'parent_team_id');
    }

    private function makeNodes(
        Collection $teams,
        Collection $teamIdsWithRoles,
        Collection $roles,
        Collection $displayedTeamIds,
        Collection $childrenCounts,
    ): Collection {
        return $teams->mapWithKeys(function ($team) use ($teamIdsWithRoles, $roles, $displayedTeamIds, $childrenCounts) {
            $teamId = (int) $team->id;
            $isSelectable = $displayedTeamIds->contains($teamId);
            $nodeRoles = $teamIdsWithRoles->get($teamId, collect())
                ->map(fn($roleId) => $roles->get($roleId) ?: (object) ['id' => $roleId, 'name' => $roleId])
                ->values();

            return [$teamId => (object) [
                'id' => $this->nodeId($teamId, $isSelectable),
                'team' => $team,
                'roles' => $nodeRoles,
                'children' => collect(),
                'depth' => 0,
                'is_selectable' => $isSelectable,
                'is_context' => !$isSelectable,
                'children_count' => (int) ($childrenCounts->get($teamId) ?? 0),
            ]];
        });
    }

    private function buildTeamRoots(Collection $nodes): Collection
    {
        $roots = collect();

        foreach ($nodes as $node) {
            $parentId = (int) $node->team->parent_team_id;

            if ($parentId && $nodes->has($parentId)) {
                $nodes->get($parentId)->children->push($node);
                continue;
            }

            $roots->push($node);
        }

        return $this->sortAndSetDepth($roots);
    }

    private function buildCommitteeRoots(Collection $nodes, Collection $pageTeamIds): Collection
    {
        $roots = collect();
        $rootIds = [];

        foreach ($pageTeamIds as $committeeId) {
            $committee = $nodes->get((int) $committeeId);

            if (!$committee) {
                continue;
            }

            $parentId = (int) $committee->team->parent_team_id;

            if (!$parentId || !$nodes->has($parentId)) {
                $roots->push($committee);
                continue;
            }

            $parent = $nodes->get($parentId);
            $parent->children->push($committee);

            if (!isset($rootIds[$parentId])) {
                $roots->push($parent);
                $rootIds[$parentId] = true;
            }
        }

        return $this->sortAndSetDepth($roots);
    }

    private function sortAndSetDepth(Collection $nodes, int $depth = 0): Collection
    {
        return $nodes
            ->sortBy(fn($node) => strtolower($node->team->team_name))
            ->values()
            ->map(function ($node) use ($depth) {
                $node->depth = $depth;
                $node->children = $this->sortAndSetDepth($node->children, $depth + 1);

                return $node;
            });
    }

    private function normalizeMode(?string $mode): string
    {
        return $mode === self::MODE_COMMITTEES ? self::MODE_COMMITTEES : self::MODE_TEAMS;
    }

    private function teamQuery(): Builder
    {
        return TeamModel::withoutGlobalScope('authUserHasPermissions');
    }

    private function nodeId(int $teamId, bool $isSelectable): string
    {
        return ($isSelectable ? 'team-' : 'team-context-') . $teamId;
    }

    private function hasCommitteeColumn(): bool
    {
        return hasColumnCached('teams', 'is_committee');
    }

}
