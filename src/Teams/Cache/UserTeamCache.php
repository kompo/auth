<?php

namespace Kompo\Auth\Teams\Cache;

use Kompo\Auth\Teams\CacheKeyBuilder;

class UserTeamCache
{
    public function __construct(private AuthCacheLayer $cache) {}

    public function userTeamAccess(int|string $userId, int|string $teamId, ?string $roleId, callable $compute): bool
    {
        return (bool) $this->cache->remember(
            CacheKeyBuilder::userTeamAccess($userId, $teamId, $roleId),
            CacheKeyBuilder::USER_TEAM_ACCESS,
            $compute
        );
    }

    public function allAccessibleTeams(int|string $userId, callable $compute)
    {
        return $this->cache->remember(
            CacheKeyBuilder::userAllAccessibleTeams($userId),
            CacheKeyBuilder::USER_ALL_ACCESSIBLE_TEAMS,
            $compute
        );
    }

    public function activeTeamRoles(int|string $userId, ?string $roleId, callable $compute)
    {
        return $this->cache->remember(
            "activeTeamRoles.{$userId}.{$roleId}",
            CacheKeyBuilder::USER_ACTIVE_TEAM_ROLES,
            $compute
        );
    }

    public function allTeamIdsWithRoles(int|string $userId, $profile, callable $compute)
    {
        return $this->cache->remember(
            "allTeamIdsWithRoles.{$userId}.{$profile}",
            CacheKeyBuilder::ALL_TEAM_IDS_WITH_ROLES,
            $compute,
            (int) config('kompo-auth.cache.role_switcher_ttl', 900)
        );
    }

    public function currentPermissionsInAllTeams(int|string $userId, callable $compute)
    {
        return $this->cache->remember(
            "currentPermissionsInAllTeams{$userId}",
            CacheKeyBuilder::USER_PERMISSIONS,
            $compute
        );
    }

    public function currentPermissionKeys(int|string $userId, $teamIds, callable $compute)
    {
        $teamIds = collect(is_iterable($teamIds) ? $teamIds : [$teamIds]);

        return $this->cache->remember(
            'currentPermissionKeys' . $userId . '|' . $teamIds->implode(','),
            CacheKeyBuilder::USER_PERMISSIONS,
            $compute
        );
    }
}
