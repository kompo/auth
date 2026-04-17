<?php

namespace Kompo\Auth\Teams\Contracts;

use Illuminate\Support\Collection;

interface TeamHierarchyInterface
{
    public function getDescendantTeamIds(int $teamId, ?string $search = null, ?int $maxDepth = null): Collection;

    public function isDescendant(int $parentTeamId, int $childTeamId): bool;

    public function getAncestorTeamIds(int $teamId): Collection;

    public function getSiblingTeamIds(int $teamId, ?string $search = ''): Collection;

    public function getBatchAncestorTeamIds(array $teamIds): Collection;

    public function getBatchDescendantTeamIdsByRoot(array $teamIds, ?string $search = ''): Collection;

    public function getBatchSiblingTeamIds(array $teamIds, ?string $search = ''): Collection;

    public function getBatchSiblingTeamIdsBySource(array $teamIds, ?string $search = ''): Collection;

    public function clearCache(?int $teamId = null): void;
}
