<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Collection;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;

class TeamHierarchyRoleProcessor
{
    public function __construct(private TeamHierarchyInterface $hierarchy) {}

    public function descendantsWithRole(int $teamId, string $role, ?string $search = ''): Collection
    {
        return $this->mapIdsToRole(
            $this->hierarchy->getDescendantTeamIds($teamId, $search),
            $role
        );
    }

    public function siblingsWithRole(int $teamId, string $role, ?string $search = ''): Collection
    {
        return $this->mapIdsToRole(
            $this->hierarchy->getSiblingTeamIds($teamId, $search),
            $role
        );
    }

    public function batchDescendantsWithRoles(array $teamIdsWithRoles, ?string $search = ''): Collection
    {
        if (empty($teamIdsWithRoles)) {
            return collect();
        }

        return $this->mapGroupedIdsToRoles(
            $this->hierarchy->getBatchDescendantTeamIdsByRoot(array_keys($teamIdsWithRoles), $search),
            $teamIdsWithRoles
        );
    }

    public function batchSiblingsWithRoles(array $teamIdsWithRoles, ?string $search = ''): Collection
    {
        if (empty($teamIdsWithRoles)) {
            return collect();
        }

        return $this->mapGroupedIdsToRoles(
            $this->hierarchy->getBatchSiblingTeamIdsBySource(array_keys($teamIdsWithRoles), $search),
            $teamIdsWithRoles
        );
    }

    private function mapIdsToRole(Collection $teamIds, string $role): Collection
    {
        return $teamIds->mapWithKeys(fn($teamId) => [$teamId => $role]);
    }

    private function mapGroupedIdsToRoles(Collection $teamIdsBySource, array $teamIdsWithRoles): Collection
    {
        $result = collect();

        foreach ($teamIdsBySource as $sourceTeamId => $teamIds) {
            $rolesForSource = $teamIdsWithRoles[$sourceTeamId] ?? null;

            if ($rolesForSource === null) {
                continue;
            }

            foreach (collect($teamIds)->unique() as $teamId) {
                $roles = $result->get($teamId, []);

                foreach ((array) $rolesForSource as $role) {
                    if (!in_array($role, $roles)) {
                        $roles[] = $role;
                    }
                }

                $result->put($teamId, $roles);
            }
        }

        return $result;
    }
}
