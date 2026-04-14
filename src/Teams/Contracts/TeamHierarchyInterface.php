<?php

namespace Kompo\Auth\Teams\Contracts;

use Illuminate\Support\Collection;

interface TeamHierarchyInterface
{
    public function getDescendantTeamIds(int $teamId, ?string $search = null, ?int $maxDepth = null): Collection;

    public function getDescendantTeamsWithRole(int $teamId, string $role, ?string $search = '', $limit = null): Collection;

    public function isDescendant(int $parentTeamId, int $childTeamId): bool;

    public function getAncestorTeamIds(int $teamId): Collection;

    public function getSiblingTeamIds(int $teamId, ?string $search = '', $limit = null): Collection;

    public function getBatchAncestorTeamIds(array $teamIds): Collection;

    public function getBatchSiblingTeamIds(array $teamIds, ?string $search = '', $limit = null): Collection;

    public function getBatchDescendantTeamsWithRoles(array $teamIdsWithRoles, ?string $search = '', $limit = null): Collection;

    public function getBatchSiblingTeamsWithRoles(array $teamIdsWithRoles, ?string $search = '', $limit = null): Collection;

    public function clearCache(?int $teamId = null): void;
}
