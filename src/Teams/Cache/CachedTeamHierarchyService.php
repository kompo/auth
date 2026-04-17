<?php

namespace Kompo\Auth\Teams\Cache;

use Illuminate\Support\Collection;
use Kompo\Auth\Teams\CacheKeyBuilder;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;
use Kompo\Auth\Teams\TeamHierarchyService;

class CachedTeamHierarchyService implements TeamHierarchyInterface
{
    public function __construct(
        private TeamHierarchyService $inner,
        private AuthCacheLayer $cache,
    ) {}

    public function getDescendantTeamIds(int $teamId, ?string $search = null, ?int $maxDepth = null): Collection
    {
        return $this->cache->remember(
            CacheKeyBuilder::teamDescendants($teamId, $maxDepth, $search),
            CacheKeyBuilder::TEAM_DESCENDANTS,
            fn() => $this->inner->getDescendantTeamIds($teamId, $search, $maxDepth)
        );
    }

    public function isDescendant(int $parentTeamId, int $childTeamId): bool
    {
        if ($parentTeamId === $childTeamId) {
            return true;
        }

        return (bool) $this->cache->remember(
            CacheKeyBuilder::teamIsDescendant($parentTeamId, $childTeamId),
            CacheKeyBuilder::TEAM_IS_DESCENDANT,
            fn() => $this->inner->isDescendant($parentTeamId, $childTeamId)
        );
    }

    public function getAncestorTeamIds(int $teamId): Collection
    {
        return $this->cache->remember(
            CacheKeyBuilder::teamAncestors($teamId),
            CacheKeyBuilder::TEAM_ANCESTORS,
            fn() => $this->inner->getAncestorTeamIds($teamId)
        );
    }

    public function getSiblingTeamIds(int $teamId, ?string $search = ''): Collection
    {
        return $this->cache->remember(
            CacheKeyBuilder::teamSiblings($teamId, $search),
            CacheKeyBuilder::TEAM_SIBLINGS,
            fn() => $this->inner->getSiblingTeamIds($teamId, $search)
        );
    }

    public function getBatchAncestorTeamIds(array $teamIds): Collection
    {
        return $this->inner->getBatchAncestorTeamIds($teamIds);
    }

    public function getBatchDescendantTeamIdsByRoot(array $teamIds, ?string $search = ''): Collection
    {
        return $this->inner->getBatchDescendantTeamIdsByRoot($teamIds, $search);
    }

    public function getBatchSiblingTeamIds(array $teamIds, ?string $search = ''): Collection
    {
        return $this->inner->getBatchSiblingTeamIds($teamIds, $search);
    }

    public function getBatchSiblingTeamIdsBySource(array $teamIds, ?string $search = ''): Collection
    {
        return $this->inner->getBatchSiblingTeamIdsBySource($teamIds, $search);
    }

    public function clearCache(?int $teamId = null): void
    {
        $this->cache->invalidateTags(CacheKeyBuilder::getTeamHierarchyCacheTypes());
    }
}
