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
        if (empty($teamIds)) {
            return $this->inner->getBatchAncestorTeamIds($teamIds);
        }

        $normalized = collect($teamIds)->filter()->unique()->sort()->values()->all();
        $key = 'batch_ancestors.' . md5(json_encode($normalized));

        return $this->cache->remember(
            $key,
            CacheKeyBuilder::TEAM_ANCESTORS,
            fn() => $this->inner->getBatchAncestorTeamIds($teamIds)
        );
    }

    public function getBatchDescendantTeamIdsByRoot(array $teamIds, ?string $search = ''): Collection
    {
        if ($search !== null && $search !== '') {
            return $this->inner->getBatchDescendantTeamIdsByRoot($teamIds, $search);
        }

        if (empty($teamIds)) {
            return $this->inner->getBatchDescendantTeamIdsByRoot($teamIds, $search);
        }

        $normalized = collect($teamIds)->filter()->unique()->sort()->values()->all();
        $key = 'batch_descendants_by_root.' . md5(json_encode($normalized));

        return $this->cache->remember(
            $key,
            CacheKeyBuilder::TEAM_DESCENDANTS,
            fn() => $this->inner->getBatchDescendantTeamIdsByRoot($teamIds, $search)
        );
    }

    public function getBatchSiblingTeamIds(array $teamIds, ?string $search = ''): Collection
    {
        if ($search !== null && $search !== '') {
            return $this->inner->getBatchSiblingTeamIds($teamIds, $search);
        }

        if (empty($teamIds)) {
            return $this->inner->getBatchSiblingTeamIds($teamIds, $search);
        }

        $normalized = collect($teamIds)->filter()->unique()->sort()->values()->all();
        $key = 'batch_siblings.' . md5(json_encode($normalized));

        return $this->cache->remember(
            $key,
            CacheKeyBuilder::TEAM_SIBLINGS,
            fn() => $this->inner->getBatchSiblingTeamIds($teamIds, $search)
        );
    }

    public function getBatchSiblingTeamIdsBySource(array $teamIds, ?string $search = ''): Collection
    {
        if ($search !== null && $search !== '') {
            return $this->inner->getBatchSiblingTeamIdsBySource($teamIds, $search);
        }

        if (empty($teamIds)) {
            return $this->inner->getBatchSiblingTeamIdsBySource($teamIds, $search);
        }

        $normalized = collect($teamIds)->filter()->unique()->sort()->values()->all();
        $key = 'batch_siblings_by_source.' . md5(json_encode($normalized));

        return $this->cache->remember(
            $key,
            CacheKeyBuilder::TEAM_SIBLINGS,
            fn() => $this->inner->getBatchSiblingTeamIdsBySource($teamIds, $search)
        );
    }

    public function clearCache(?int $teamId = null): void
    {
        $this->cache->invalidateTags(CacheKeyBuilder::getTeamHierarchyCacheTypes());
    }
}
