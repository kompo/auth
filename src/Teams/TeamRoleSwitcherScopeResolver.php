<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Collection;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;
use Kompo\Auth\Teams\Contracts\TeamRoleAccessDataSourceInterface;

class TeamRoleSwitcherScopeResolver
{
    public function __construct(
        private TeamRoleAccessDataSourceInterface $data,
        private TeamHierarchyRoleProcessor $roleProcessor,
        private TeamHierarchyInterface $hierarchy,
        private TeamRoleSwitcherTeamRepository $teams,
        private TeamRoleSwitcherScopeCodec $codec,
    ) {}

    public function resolve($user, int|string|null $profile, string $mode): Collection
    {
        $activeRoles = $this->data->activeTeamRoles($user, $profile)
            ->filter(fn($teamRole) => $teamRole->team)
            ->values();

        $originRoles = $activeRoles
            ->filter(fn($teamRole) => empty($teamRole->parent_team_role_id))
            ->values();

        if ($originRoles->isEmpty()) {
            $originRoles = $activeRoles;
        }

        if ($originRoles->isEmpty()) {
            return collect();
        }

        return $originRoles
            ->groupBy('role')
            ->flatMap(fn(Collection $roleGroup, string $roleId) => $this->scopesForRole($roleId, $roleGroup, $mode))
            ->sortBy(fn(TeamRoleSwitcherScope $scope) => implode(':', [
                str_pad((string) $scope->rootDepth, 4, '0', STR_PAD_LEFT),
                strtolower((string) ($scope->rootTeam->team_name ?? '')),
                strtolower($scope->roleLabel),
            ]))
            ->values();
    }

    public function find($user, int|string|null $profile, string $mode, string $scopeKey): ?TeamRoleSwitcherScope
    {
        return $this->resolve($user, $profile, $mode)->first(
            fn(TeamRoleSwitcherScope $scope) => $scope->key === $scopeKey
        );
    }

    private function scopesForRole(string $roleId, Collection $roleGroup, string $mode): Collection
    {
        $accessibleIds = $this->accessibleTeamIdsForRoleGroup($roleGroup);

        if ($accessibleIds->isEmpty()) {
            return collect();
        }

        $accessibleTeams = $this->teams->findMany($accessibleIds->all())
            ->filter(fn($team) => $this->teams->isSelectableForMode($team, $mode))
            ->keyBy(fn($team) => (int) $team->id);

        if ($accessibleTeams->isEmpty()) {
            return collect();
        }

        $accessibleIds = $accessibleTeams->keys()->map(fn($id) => (int) $id)->values();
        $accessibleIndex = $accessibleIds->flip();
        $ancestorsByTarget = $this->hierarchy->getBatchAncestorTeamIdsByTarget($accessibleIds->all());
        $roleLabel = $this->roleLabel($roleGroup->first(), $roleId);

        $rootIds = $accessibleIds
            ->filter(function (int $teamId) use ($ancestorsByTarget, $accessibleIndex) {
                return !$ancestorsByTarget
                    ->get($teamId, collect())
                    ->contains(fn($ancestorId) => $accessibleIndex->has((int) $ancestorId));
            })
            ->values();

        if ($rootIds->isEmpty()) {
            return collect();
        }

        $rootDepths = $this->hierarchy->getBatchAncestorTeamIdsByTarget($rootIds->all())
            ->map(fn(Collection $ancestors) => $ancestors->count());

        return $rootIds->map(function (int $rootTeamId) use (
            $roleId,
            $roleLabel,
            $accessibleTeams,
            $accessibleIds,
            $ancestorsByTarget,
            $rootDepths,
        ) {
            $rootTeam = $accessibleTeams->get($rootTeamId);

            if (!$rootTeam) {
                return null;
            }

            $scopeIndex = [];

            foreach ($accessibleIds as $teamId) {
                if ($teamId === $rootTeamId) {
                    $scopeIndex[$teamId] = true;
                    continue;
                }

                if ($ancestorsByTarget->get($teamId, collect())->contains($rootTeamId)) {
                    $scopeIndex[$teamId] = true;
                }
            }

            return new TeamRoleSwitcherScope(
                key: $this->codec->scopeKey($roleId, $rootTeamId),
                roleId: $roleId,
                roleLabel: $roleLabel,
                rootTeamId: $rootTeamId,
                rootTeam: $rootTeam,
                accessibleTeamIdsIndex: $scopeIndex,
                rootDepth: (int) ($rootDepths->get($rootTeamId) ?? 0),
            );
        })->filter()->values();
    }

    private function accessibleTeamIdsForRoleGroup(Collection $roleGroup): Collection
    {
        $directIds = $roleGroup->pluck('team_id')->map(fn($id) => (int) $id)->filter()->values();
        $belowRoles = $roleGroup->filter(fn($teamRole) => $teamRole->getRoleHierarchyAccessBelow());
        $neighborRoles = $roleGroup->filter(fn($teamRole) => $teamRole->getRoleHierarchyAccessNeighbors());

        $accessibleIds = $directIds;

        if ($belowRoles->isNotEmpty()) {
            $accessibleIds = $accessibleIds->concat(array_keys(
                $this->roleProcessor
                    ->batchDescendantsWithRoles($this->teamIdsWithRoles($belowRoles))
                    ->all()
            ));
        }

        if ($neighborRoles->isNotEmpty()) {
            $accessibleIds = $accessibleIds->concat(array_keys(
                $this->roleProcessor
                    ->batchSiblingsWithRoles($this->teamIdsWithRoles($neighborRoles))
                    ->all()
            ));
        }

        return $accessibleIds
            ->map(fn($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
    }

    private function teamIdsWithRoles(Collection $teamRoles): array
    {
        return $teamRoles
            ->filter(fn($teamRole) => $teamRole->team)
            ->mapWithKeys(fn($teamRole) => [
                (int) $teamRole->team_id => [(string) $teamRole->role],
            ])
            ->all();
    }

    private function roleLabel($teamRole, string $fallback): string
    {
        if ($teamRole?->relationLoaded('roleRelation') && $teamRole->roleRelation) {
            return (string) $teamRole->roleRelation->name;
        }

        return $this->data->roleName($fallback);
    }
}
