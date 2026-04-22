<?php

namespace Kompo\Auth\Teams\Cache;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Teams\Cache\UserContextCache;
use Kompo\Auth\Teams\CacheKeyBuilder;
use Kompo\Auth\Teams\Contracts\PermissionResolverInterface;
use Kompo\Auth\Teams\PermissionAccessIndex;
use Kompo\Auth\Teams\PermissionResolver;

class CachedPermissionResolver implements PermissionResolverInterface
{
    public function __construct(
        private PermissionResolver $inner,
        private AuthCacheLayer $cache,
        private UserContextCache $context,
        private UserCacheVersion $versions,
        private UserPermissionSet $permissionSet,
    ) {
        $inner->setPublicApi($this);
    }

    public function userHasPermission(
        int $userId,
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL,
        $teamIds = null
    ): bool {
        $version = $this->versions->get($userId);
        $requestKey = 'user_permission_check.' . $userId . '.v' . $version . '.' . $permissionKey . '|' . $type->value . '|' . $this->teamIdsKey($teamIds);

        return $this->cache->rememberRequest($requestKey, function () use ($userId, $permissionKey, $type, $teamIds) {
            if (globalSecurityBypass()) {
                return true;
            }

            if ($this->userIsSuperAdmin($userId)) {
                return true;
            }

            // Fast path: try Redis set check first.
            $setResult = $this->permissionSet->check($userId, $permissionKey, $type, $teamIds);

            if ($setResult === null) {
                // Not materialized or Redis unavailable: resolve once, build the index,
                // and use that same index both for this answer and for future set checks.
                $permissions = collect($this->getUserPermissionsOptimized($userId, $teamIds));
                $permissionIndex = PermissionAccessIndex::fromPermissions($permissions);

                // Materialize for subsequent requests (no-op on non-Redis).
                $this->permissionSet->materialize($userId, $permissionIndex, $teamIds);

                return $permissionIndex->allows($permissionKey, $type);
            }

            return $setResult;
        });
    }

    public function getTeamsWithPermissionForUser(
        int $userId,
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL
    ) {
        $version = $this->versions->get($userId);
        $key = CacheKeyBuilder::userTeamsWithPermission($userId, $permissionKey, $type->value, $version);

        return $this->cache->remember(
            $key,
            CacheKeyBuilder::USER_TEAMS_WITH_PERMISSION,
            fn() => $this->inner->getTeamsWithPermissionForUser($userId, $permissionKey, $type)
        );
    }

    public function getAllAccessibleTeamsForUser(int $userId)
    {
        $version = $this->versions->get($userId);
        $key = CacheKeyBuilder::userAllAccessibleTeams($userId, $version);

        return $this->cache->remember(
            $key,
            CacheKeyBuilder::USER_ALL_ACCESSIBLE_TEAMS,
            fn() => $this->inner->getAllAccessibleTeamsForUser($userId)
        );
    }

    public function getUserPermissionsOptimized(int $userId, $teamIds = null)
    {
        $version = $this->versions->get($userId);
        $key = CacheKeyBuilder::userPermissions($userId, $teamIds, $version);

        return $this->cache->remember(
            $key,
            CacheKeyBuilder::USER_PERMISSIONS,
            fn() => $this->inner->getUserPermissionsOptimized($userId, $teamIds)
        );
    }

    public function getUserActiveTeamRoles(int $userId, $teamIds = null): Collection
    {
        $version = $this->versions->get($userId);
        $key = 'user_active_team_roles.' . $userId . '.v' . $version . '.' . $this->teamIdsKey($teamIds);

        return $this->cache->remember(
            $key,
            CacheKeyBuilder::USER_ACTIVE_TEAM_ROLES,
            fn() => $this->inner->getUserActiveTeamRoles($userId, $teamIds)
        );
    }

    public function getRolePermissions($role): array
    {
        if (!$role) {
            return [];
        }

        return $this->cache->remember(
            CacheKeyBuilder::rolePermissions($role->id),
            CacheKeyBuilder::ROLE_PERMISSIONS,
            fn() => $this->inner->getRolePermissions($role)
        );
    }

    public function getTeamRolePermissions(TeamRole $teamRole): array
    {
        return $this->cache->remember(
            CacheKeyBuilder::teamRolePermissions($teamRole->id),
            CacheKeyBuilder::TEAM_ROLE_PERMISSIONS,
            fn() => $this->inner->getTeamRolePermissions($teamRole)
        );
    }

    public function getTeamRoleAccessibleTeams(TeamRole $teamRole): array
    {
        return $this->cache->remember(
            CacheKeyBuilder::teamRoleAccess($teamRole->id),
            CacheKeyBuilder::TEAM_ROLE_ACCESS,
            fn() => $this->inner->getTeamRoleAccessibleTeams($teamRole)
        );
    }

    public function getAccessibleTeamIds(Collection $targetTeamIds): Collection
    {
        $key = 'accessible_teams.' . md5(json_encode($targetTeamIds->filter()->unique()->sort()->values()->all()));

        return $this->cache->remember(
            $key,
            CacheKeyBuilder::ACCESSIBLE_TEAMS,
            fn() => $this->inner->getAccessibleTeamIds($targetTeamIds)
        );
    }

    public function clearRequestCache(): void
    {
        $this->cache->flushRequestCache();
    }

    public function clearUserCache(int $userId): void
    {
        $this->cache->invalidateTags(CacheKeyBuilder::getUserSpecificCacheTypes());
    }

    public function clearAllCache(): void
    {
        $this->cache->invalidateAll();
    }

    public function getCacheStats(): array
    {
        return $this->cache->stats();
    }

    public function batchWarmCache(array $userIds): void
    {
        foreach ($userIds as $userId) {
            try {
                $permissionIndex = PermissionAccessIndex::fromPermissions(
                    $this->getUserPermissionsOptimized($userId)
                );
                $this->permissionSet->materialize($userId, $permissionIndex, null);

                $this->getAllAccessibleTeamsForUser($userId);
            } catch (\Throwable $e) {
                \Log::warning("Failed to warm cache for user {$userId}: " . $e->getMessage());
            }
        }
    }

    public function warmRolePermissions($role): void
    {
        $this->getRolePermissions($role);
    }

    public function getTeamsQueryWithPermissionForUser(
        int $userId,
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL,
        ?string $teamTableAlias = null
    ): Builder {
        return $this->inner->getTeamsQueryWithPermissionForUser($userId, $permissionKey, $type, $teamTableAlias);
    }

    public function getUsersQueryWithPermission(
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL,
        $teamIds = null,
        ?string $usersTableAlias = null
    ): Builder {
        return $this->inner->getUsersQueryWithPermission($permissionKey, $type, $teamIds, $usersTableAlias);
    }

    public function getPerformanceMetrics(): array
    {
        return array_merge($this->inner->getPerformanceMetrics(), [
            'cache_layer' => $this->cache->stats(),
        ]);
    }

    private function userIsSuperAdmin(int $userId): bool
    {
        return $this->context->isSuperAdmin($userId, fn() => $this->inner->userIsSuperAdmin($userId));
    }

    private function teamIdsKey($teamIds): string
    {
        if ($teamIds === null) {
            return 'global';
        }

        if ($teamIds instanceof Collection) {
            $teamIds = $teamIds->all();
        }

        if (is_iterable($teamIds)) {
            return md5(json_encode(collect($teamIds)->sort()->values()));
        }

        return (string) $teamIds;
    }
}
