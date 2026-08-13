<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Collection;

/**
 * Buckets committee scopes by the level of their root team.
 *
 * Committees rarely nest, so every accessible one resolves to its own root scope; without
 * this the switcher shows them as one flat list. Levels are read off the already-loaded
 * `rootTeam`, so bucketing costs no query, and only through the duck-typed accessors on
 * TeamRoleSwitcherTeamRepository, so auth keeps knowing nothing about the host's level enum.
 */
class TeamRoleSwitcherLevelGrouper
{
    public const UNKNOWN_LEVEL = 0;

    private const UNKNOWN_SORT = 9999;

    public function __construct(
        private TeamRoleSwitcherTeamRepository $teams,
    ) {}

    /**
     * @return Collection<int, array{level: int, label: ?string, class: ?string, scopes: Collection}>
     */
    public function bucket(Collection $scopes): Collection
    {
        return $scopes
            ->groupBy(fn (TeamRoleSwitcherScope $scope) => $this->sortValue($scope))
            ->sortKeys()
            ->map(fn (Collection $levelScopes, $sortValue) => [
                'level' => $sortValue === self::UNKNOWN_SORT ? self::UNKNOWN_LEVEL : (int) $sortValue,
                'label' => $this->teams->teamLevelLabel($levelScopes->first()->rootTeam),
                'class' => $this->teams->teamLevelClass($levelScopes->first()->rootTeam),
                'scopes' => $this->sortByName($levelScopes),
            ])
            ->values();
    }

    public function scopesForLevel(Collection $scopes, int $level): Collection
    {
        return $this->bucket($scopes)->firstWhere('level', $level)['scopes'] ?? collect();
    }

    public function levelOf(TeamRoleSwitcherScope $scope): int
    {
        $sortValue = $this->sortValue($scope);

        return $sortValue === self::UNKNOWN_SORT ? self::UNKNOWN_LEVEL : $sortValue;
    }

    private function sortValue(TeamRoleSwitcherScope $scope): int
    {
        return $this->teams->teamLevelSortValue($scope->rootTeam) ?? self::UNKNOWN_SORT;
    }

    private function sortByName(Collection $scopes): Collection
    {
        return $scopes
            ->sortBy(fn (TeamRoleSwitcherScope $scope) => strtolower((string) ($scope->rootTeam->team_name ?? '')))
            ->values();
    }
}
