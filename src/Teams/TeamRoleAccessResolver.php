<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Collection;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;
use Kompo\Auth\Teams\Contracts\TeamRoleAccessDataSourceInterface;
use Kompo\Auth\Teams\Contracts\TeamRoleAccessResolverInterface;

class TeamRoleAccessResolver implements TeamRoleAccessResolverInterface
{
    public function __construct(
        private TeamRoleAccessDataSourceInterface $data,
        private TeamHierarchyRoleProcessor $roleProcessor,
        private TeamHierarchyInterface $hierarchy,
    ) {}

    public function accessibleTeamIds($user, int|string|null $profile = null, ?string $search = ''): array
    {
        return array_keys($this->teamIdsWithRoles($user, $profile, $search));
    }

    public function teamIdsWithRoles($user, int|string|null $profile = 1, ?string $search = ''): array
    {
        $teamRoles = $this->data->activeTeamRoles($user, $profile);

        if ($teamRoles->isEmpty()) {
            return [];
        }

        $result = collect();

        foreach ($this->groupTeamRolesByHierarchy($teamRoles) as $hierarchyType => $roleGroup) {
            $result = $this->mergeBatchResults(
                $result,
                $this->processBatchHierarchyGroup($hierarchyType, $roleGroup, $search)
            );
        }

        return $result->all();
    }

    public function hasAccessToTeam($user, int $teamId, ?string $roleId = null, int|string|null $profile = 1): bool
    {
        $team = $this->data->teamForAccessCheck($teamId);

        if (!$team) {
            return false;
        }

        $access = $this->resolveForCandidates(
            collect([$team]),
            $user,
            $profile,
            includeCurrentRole: false
        )[$teamId] ?? null;

        if (!$access) {
            return false;
        }

        $roles = collect($access['roles'] ?? [])->pluck('id');
        $switchRole = $access['switchRole']['id'] ?? null;

        if (!$roleId) {
            return $roles->isNotEmpty() || $switchRole !== null;
        }

        return $roles->contains($roleId) || $switchRole === $roleId;
    }

    public function resolveForCandidates(
        Collection $candidateTeams,
        $user,
        int|string|null $profile,
        ?string $mode = null,
        $currentTeamRole = null,
        bool $includeCurrentRole = true,
    ): array {
        $candidateIds = $candidateTeams->pluck('id')->map(fn($id) => (int) $id)->values();

        if ($candidateIds->isEmpty()) {
            return [];
        }

        $candidateParents = $candidateTeams->mapWithKeys(fn($team) => [
            (int) $team->id => $team->parent_team_id ? (int) $team->parent_team_id : null,
        ]);
        $ancestorsByCandidate = $this->hierarchy->getBatchAncestorTeamIdsByTarget($candidateIds->all());
        $activeTeamRoles = $this->data->activeTeamRoles($user, $profile);
        $currentTeamRole = $includeCurrentRole && !$currentTeamRole && function_exists('currentTeamRole')
            ? currentTeamRole()
            : $currentTeamRole;
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
                if (!$this->teamRoleGrantsCandidate($teamRole, $candidateId, $candidateParentId, $candidateAncestors)) {
                    continue;
                }

                $rolesByTeam[$candidateId] ??= [];
                $role = $this->rolePayload($teamRole, $candidateId, $currentTeamRole);

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

    public function activeRoleBranchIndex($user, int|string|null $profile, ?string $mode = null): Collection
    {
        $roleTeamIds = $this->data->activeTeamRoles($user, $profile)
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

        foreach ($this->hierarchy->getBatchAncestorTeamIdsByTarget($roleTeamIds->all()) as $ancestorIds) {
            $branchIds = $branchIds->concat($ancestorIds);
        }

        return $branchIds->unique()->flip();
    }

    private function groupTeamRolesByHierarchy(Collection $teamRoles): array
    {
        $grouped = [
            'direct' => [],
            'below' => [],
            'neighbors' => [],
            'below_and_neighbors' => [],
        ];

        foreach ($teamRoles as $teamRole) {
            $hasBelow = $teamRole->getRoleHierarchyAccessBelow();
            $hasNeighbors = $teamRole->getRoleHierarchyAccessNeighbors();

            if ($hasBelow && $hasNeighbors) {
                $grouped['below_and_neighbors'][] = $teamRole;
            } elseif ($hasBelow) {
                $grouped['below'][] = $teamRole;
            } elseif ($hasNeighbors) {
                $grouped['neighbors'][] = $teamRole;
            } else {
                $grouped['direct'][] = $teamRole;
            }
        }

        return array_filter($grouped, fn($group) => !empty($group));
    }

    private function processBatchHierarchyGroup(string $hierarchyType, array $teamRoles, ?string $search): Collection
    {
        $batchResults = $this->getDirectTeamAccess($teamRoles, $search);

        if ($hierarchyType === 'below' || $hierarchyType === 'below_and_neighbors') {
            $batchResults = $this->mergeBatchResults(
                $batchResults,
                $this->getBatchDescendantAccess($teamRoles, $search)
            );
        }

        if ($hierarchyType === 'neighbors' || $hierarchyType === 'below_and_neighbors') {
            $batchResults = $this->mergeBatchResults(
                $batchResults,
                $this->getBatchNeighborAccess($teamRoles, $search)
            );
        }

        return $batchResults;
    }

    private function getDirectTeamAccess(array $teamRoles, ?string $search): Collection
    {
        $directTeams = collect();
        $search = trim((string) $search);

        foreach ($teamRoles as $teamRole) {
            if (!$teamRole->team) {
                continue;
            }

            if ($search !== '' && !str_contains(strtolower($teamRole->team->team_name), strtolower($search))) {
                continue;
            }

            $this->addRoleToTeamMap($directTeams, (int) $teamRole->team->id, $teamRole->role);
        }

        return $directTeams;
    }

    private function getBatchDescendantAccess(array $teamRoles, ?string $search): Collection
    {
        return $this->roleProcessor->batchDescendantsWithRoles(
            $this->teamIdsWithRolesFromTeamRoles($teamRoles),
            $search
        );
    }

    private function getBatchNeighborAccess(array $teamRoles, ?string $search): Collection
    {
        return $this->roleProcessor->batchSiblingsWithRoles(
            $this->teamIdsWithRolesFromTeamRoles($teamRoles),
            $search
        );
    }

    private function teamIdsWithRolesFromTeamRoles(array $teamRoles): array
    {
        $teamIdsWithRoles = [];

        foreach ($teamRoles as $teamRole) {
            if (!$teamRole->team) {
                continue;
            }

            $teamIdsWithRoles[$teamRole->team->id] = array_values(array_unique([
                ...($teamIdsWithRoles[$teamRole->team->id] ?? []),
                $teamRole->role,
            ]));
        }

        return $teamIdsWithRoles;
    }

    private function mergeBatchResults(Collection $result, Collection $batchResults): Collection
    {
        foreach ($batchResults as $teamId => $roles) {
            foreach ((array) $roles as $role) {
                $this->addRoleToTeamMap($result, (int) $teamId, $role);
            }
        }

        return $result;
    }

    private function addRoleToTeamMap(Collection $teamRoles, int $teamId, string $role): void
    {
        $roles = $teamRoles->get($teamId, []);

        if (!in_array($role, $roles)) {
            $roles[] = $role;
        }

        $teamRoles->put($teamId, $roles);
    }

    private function teamRoleGrantsCandidate($teamRole, int $candidateId, ?int $candidateParentId, Collection $candidateAncestors): bool
    {
        $grantingTeamId = (int) $teamRole->team_id;

        if ($grantingTeamId === $candidateId) {
            return true;
        }

        if ($teamRole->getRoleHierarchyAccessBelow() && $candidateAncestors->has($grantingTeamId)) {
            return true;
        }

        if (!$teamRole->getRoleHierarchyAccessNeighbors()) {
            return false;
        }

        $roleTeamParentId = $teamRole->team?->parent_team_id ? (int) $teamRole->team->parent_team_id : null;

        return $roleTeamParentId && $candidateParentId && $roleTeamParentId === $candidateParentId;
    }

    private function rolePayload($teamRole, int $candidateId, $currentTeamRole): array
    {
        return [
            'id' => $teamRole->role,
            'label' => $this->roleLabel($teamRole),
            'isCurrent' => $currentTeamRole
                && $currentTeamRole->team_id == $candidateId
                && $currentTeamRole->role == $teamRole->role,
        ];
    }

    private function roleLabel($teamRole): string
    {
        if ($teamRole->relationLoaded('roleRelation') && $teamRole->roleRelation) {
            return $teamRole->roleRelation->name;
        }

        return $this->data->roleName($teamRole->role);
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

    private function isSelectableForMode($team, ?string $mode): bool
    {
        if ($mode === null) {
            return true;
        }

        $isCommittee = $this->hasCommitteeColumn() && (bool) ($team->is_committee ?? false);

        return $mode === TeamAccessHierarchyBuilder::MODE_COMMITTEES ? $isCommittee : !$isCommittee;
    }

    private function hasCommitteeColumn(): bool
    {
        return hasColumnCached('teams', 'is_committee');
    }
}
