<?php

namespace Kompo\Auth\Teams\Cache;

use Kompo\Auth\Teams\CacheKeyBuilder;

class UserTeamCache
{
    public function __construct(
        private AuthCacheLayer $cache,
        private UserCacheVersion $versions,
    ) {}

    public function userTeamAccess(int|string $userId, int|string $teamId, ?string $roleId, callable $compute): bool
    {
        $version = $this->versions->get($userId);

        return (bool) $this->cache->remember(
            CacheKeyBuilder::userTeamAccess($userId, $teamId, $roleId, $version),
            CacheKeyBuilder::USER_TEAM_ACCESS,
            $compute
        );
    }

    public function allAccessibleTeams(int|string $userId, callable $compute)
    {
        $version = $this->versions->get($userId);

        return $this->cache->remember(
            CacheKeyBuilder::userAllAccessibleTeams($userId, $version),
            CacheKeyBuilder::USER_ALL_ACCESSIBLE_TEAMS,
            $compute
        );
    }

    public function activeTeamRoles(int|string $userId, ?string $roleId, callable $compute)
    {
        $version = $this->versions->get($userId);

        return $this->cache->remember(
            "activeTeamRoles.{$userId}.v{$version}.{$roleId}",
            CacheKeyBuilder::USER_ACTIVE_TEAM_ROLES,
            $compute
        );
    }

    public function activeTeamRolesByProfile(int|string $userId, $profile, callable $compute)
    {
        $version = $this->versions->get($userId);
        $profileKey = $profile === null ? 'all' : (string) $profile;

        return $this->cache->remember(
            "activeTeamRoles.{$userId}.v{$version}.profile.{$profileKey}",
            CacheKeyBuilder::USER_ACTIVE_TEAM_ROLES,
            $compute
        );
    }

    public function allTeamIdsWithRoles(int|string $userId, $profile, callable $compute)
    {
        $version = $this->versions->get($userId);
        $profileKey = $profile === null ? 'all' : (string) $profile;

        return $this->cache->remember(
            "allTeamIdsWithRoles.{$userId}.v{$version}.{$profileKey}",
            CacheKeyBuilder::ALL_TEAM_IDS_WITH_ROLES,
            $compute,
            (int) config('kompo-auth.cache.role_switcher_ttl', 900)
        );
    }

}
