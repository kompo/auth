<?php

namespace Kompo\Auth\Teams\Cache;

use Kompo\Auth\Teams\CacheKeyBuilder;

class UserContextCache
{
    public function __construct(private AuthCacheLayer $cache) {}

    public function currentTeamRole(int|string $userId, callable $compute)
    {
        return $this->cache->remember(
            CacheKeyBuilder::currentTeamRole($userId),
            CacheKeyBuilder::CURRENT_TEAM_ROLE,
            $compute
        );
    }

    public function currentTeam(int|string $userId, callable $compute)
    {
        return $this->cache->remember(
            CacheKeyBuilder::currentTeam($userId),
            CacheKeyBuilder::CURRENT_TEAM,
            $compute
        );
    }

    public function isSuperAdmin(int|string $userId, callable $compute): bool
    {
        return (bool) $this->cache->remember(
            CacheKeyBuilder::userSuperAdmin($userId),
            CacheKeyBuilder::USER_SUPER_ADMIN,
            $compute,
            (int) config('kompo-auth.cache.super_admin_ttl', 3600)
        );
    }

    public function putCurrentTeamRole(int|string $userId, $teamRole): void
    {
        $this->cache->put(
            CacheKeyBuilder::currentTeamRole($userId),
            CacheKeyBuilder::CURRENT_TEAM_ROLE,
            $teamRole
        );
    }

    public function putCurrentTeam(int|string $userId, $team): void
    {
        $this->cache->put(
            CacheKeyBuilder::currentTeam($userId),
            CacheKeyBuilder::CURRENT_TEAM,
            $team
        );
    }

    public function putIsSuperAdmin(int|string $userId, bool $isSuperAdmin): void
    {
        $this->cache->put(
            CacheKeyBuilder::userSuperAdmin($userId),
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
