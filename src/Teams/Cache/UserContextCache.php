<?php

namespace Kompo\Auth\Teams\Cache;

use Kompo\Auth\Teams\CacheKeyBuilder;

class UserContextCache
{
    public function __construct(
        private AuthCacheLayer $cache,
        private UserCacheVersion $versions,
    ) {}

    public function currentTeamRole(int|string $userId, callable $compute)
    {
        $version = $this->versions->get($userId);

        return $this->cache->remember(
            CacheKeyBuilder::currentTeamRole($userId, $version),
            CacheKeyBuilder::CURRENT_TEAM_ROLE,
            $compute
        );
    }

    public function currentTeam(int|string $userId, callable $compute)
    {
        $version = $this->versions->get($userId);

        return $this->cache->remember(
            CacheKeyBuilder::currentTeam($userId, $version),
            CacheKeyBuilder::CURRENT_TEAM,
            $compute
        );
    }

    public function isSuperAdmin(int|string $userId, callable $compute): bool
    {
        $version = $this->versions->get($userId);

        return (bool) $this->cache->remember(
            CacheKeyBuilder::userSuperAdmin($userId, $version),
            CacheKeyBuilder::USER_SUPER_ADMIN,
            $compute,
            (int) config('kompo-auth.cache.super_admin_ttl', 3600)
        );
    }

    public function putCurrentTeamRole(int|string $userId, $teamRole): void
    {
        $version = $this->versions->get($userId);

        $this->cache->put(
            CacheKeyBuilder::currentTeamRole($userId, $version),
            CacheKeyBuilder::CURRENT_TEAM_ROLE,
            $teamRole
        );
    }

    public function putCurrentTeam(int|string $userId, $team): void
    {
        $version = $this->versions->get($userId);

        $this->cache->put(
            CacheKeyBuilder::currentTeam($userId, $version),
            CacheKeyBuilder::CURRENT_TEAM,
            $team
        );
    }

    public function putIsSuperAdmin(int|string $userId, bool $isSuperAdmin): void
    {
        $version = $this->versions->get($userId);

        $this->cache->put(
            CacheKeyBuilder::userSuperAdmin($userId, $version),
            CacheKeyBuilder::USER_SUPER_ADMIN,
            $isSuperAdmin,
            (int) config('kompo-auth.cache.super_admin_ttl', 3600)
        );
    }

    public function clear(): void
    {
        $this->cache->invalidateTags([
            CacheKeyBuilder::CURRENT_TEAM_ROLE,
            CacheKeyBuilder::CURRENT_TEAM,
            CacheKeyBuilder::USER_SUPER_ADMIN,
        ]);
    }
}
