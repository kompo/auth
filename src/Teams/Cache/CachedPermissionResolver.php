<?php

namespace Kompo\Auth\Teams\Cache;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Teams\CacheKeyBuilder;
use Kompo\Auth\Teams\Contracts\PermissionResolverInterface;
use Kompo\Auth\Teams\PermissionResolver;

class CachedPermissionResolver implements PermissionResolverInterface
{
    public function __construct(
        private PermissionResolver $inner,
        private AuthCacheLayer $cache,
    ) {}

    public function userHasPermission(
        int $userId,
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL,
        $teamIds = null
    ): bool {
        $requestKey = 'user_permission_check.' . $userId . '.' . $permissionKey . '|' . $type->value . '|' . $this->teamIdsKey($teamIds);

        return $this->cache->rememberRequest($requestKey, function () use ($userId, $permissionKey, $type, $teamIds) {
            if (globalSecurityBypass()) {
                return true;
            }

            if ($this->userIsSuperAdmin($userId)) {
                return true;
            }

            $permissions = collect($this->getUserPermissionsOptimized($userId, $teamIds));

            if ($this->inner->hasExplicitDeny($permissions, $permissionKey)) {
                return false;
            }

            return $this->inner->hasRequiredPermission($permissions, $permissionKey, $type);
        });
    }

    public function getTeamsWithPermissionForUser(
        int $userId,
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL
    ) {
        $key = CacheKeyBuilder::userTeamsWithPermission($userId, $permissionKey, $type->value);

        return $this->cache->remember(
            $key,
            CacheKeyBuilder::USER_TEAMS_WITH_PERMISSION,
            fn() => $this->inner->getTeamsWithPermissionForUser($userId, $permissionKey, $type)
        );
    }

    public function getAllAccessibleTeamsForUser(int $userId)
    {
        $key = CacheKeyBuilder::userAllAccessibleTeams($userId);

        return $this->cache->remember(
            $key,
            CacheKeyBuilder::USER_ALL_ACCESSIBLE_TEAMS,
            fn() => $this->inner->getAllAccessibleTeamsForUser($userId)
        );
    }

    public function getUserPermissionsOptimized(int $userId, $teamIds = null)
    {
        $key = CacheKeyBuilder::userPermissions($userId, $teamIds);

        return $this->cache->remember(
            $key,
            CacheKeyBuilder::USER_PERMISSIONS,
            fn() => $this->inner->getUserPermissionsOptimized($userId, $teamIds)
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
                $this->getUserPermissionsOptimized($userId);
                $this->getAllAccessibleTeamsForUser($userId);
            } catch (\Throwable $e) {
                \Log::warning("Failed to warm cache for user {$userId}: " . $e->getMessage());
            }
        }
    }

    public function warmRolePermissions($role): void
    {
        if (!$role) {
            return;
        }

        $key = CacheKeyBuilder::rolePermissions($role->id);

        $this->cache->remember(
            $key,
            CacheKeyBuilder::ROLE_PERMISSIONS,
            fn() => $this->inner->getRolePermissions($role)
        );
    }

    public function getTeamsQueryWithPermissionForUser(
        int $userId,
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL,
        ?string $teamTableAlias = 'teams'
    ): Builder {
        return $this->inner->getTeamsQueryWithPermissionForUser($userId, $permissionKey, $type, $teamTableAlias);
    }

    public function getPerformanceMetrics(): array
    {
        return array_merge($this->inner->getPerformanceMetrics(), [
            'cache_layer' => $this->cache->stats(),
        ]);
    }

    private function userIsSuperAdmin(int $userId): bool
    {
        $key = CacheKeyBuilder::userSuperAdmin($userId);

        return (bool) $this->cache->remember(
            $key,
            CacheKeyBuilder::USER_SUPER_ADMIN,
            fn() => $this->inner->userIsSuperAdmin($userId),
            (int) config('kompo-auth.cache.super_admin_ttl', 3600)
        );
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
