<?php

namespace Kompo\Auth\Teams;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kompo\Auth\Facades\TeamModel;

class TeamRoleSwitcherTeamRepository
{
    public function children(string $mode, ?int $parentId, int $limit, int $offset): Collection
    {
        return $this->childrenQuery($mode, $parentId)
            ->skip($offset)
            ->take($limit)
            ->get();
    }

    public function childrenTotal(string $mode, ?int $parentId): int
    {
        return $this->childrenQuery($mode, $parentId)->count();
    }

    public function childrenQuery(string $mode, ?int $parentId): Builder
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

    public function search(string $mode, string $search, int $limit, int $offset = 0): Collection
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

    public function findMany(array $teamIds): Collection
    {
        $teamIds = collect($teamIds)->filter()->unique()->values()->all();

        if (empty($teamIds)) {
            return collect();
        }

        return $this->teamQuery()->whereIn('teams.id', $teamIds)->get();
    }

    public function countDirectChildren(int $teamId, string $mode): int
    {
        $query = $this->teamQuery()->where('teams.parent_team_id', $teamId);
        $this->applyModeFilter($query, $mode);

        return $query->count();
    }

    public function countDirectCommittees(int $teamId): int
    {
        if (!$this->hasCommitteeColumn()) {
            return 0;
        }

        return $this->teamQuery()
            ->where('teams.parent_team_id', $teamId)
            ->where('teams.is_committee', 1)
            ->count();
    }

    public function teamQuery(): Builder
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

    public function applyModeFilter(Builder $query, string $mode, bool $search = false): void
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

    public function isCommittee($team): bool
    {
        return $this->hasCommitteeColumn() && (bool) ($team->is_committee ?? false);
    }

    public function isSelectableForMode($team, string $mode): bool
    {
        $isCommittee = $this->isCommittee($team);

        return $mode === TeamAccessHierarchyBuilder::MODE_COMMITTEES ? $isCommittee : !$isCommittee;
    }

    public function teamLevelLabel($team): ?string
    {
        $label = $this->teamLevelMethodResult($team, 'label');

        return $label === null || $label === '' ? null : (string) $label;
    }

    public function teamLevelClass($team): ?string
    {
        $class = $this->teamLevelMethodResult($team, 'class')
            ?? $this->teamLevelMethodResult($team, 'classes');

        return $class === null || $class === '' ? null : (string) $class;
    }

    public function hasCommitteeColumn(): bool
    {
        return hasColumnCached('teams', 'is_committee');
    }

    public function hasTeamLevelColumn(): bool
    {
        return hasColumnCached('teams', 'team_level');
    }

    private function teamLevelMethodResult($team, string $method)
    {
        if (!$this->hasTeamLevelColumn()) {
            return null;
        }

        $level = $team->team_level ?? null;

        if (!is_object($level) || !method_exists($level, $method)) {
            return null;
        }

        return $level->{$method}();
    }
}
