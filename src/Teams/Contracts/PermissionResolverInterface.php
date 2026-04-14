<?php

namespace Kompo\Auth\Teams\Contracts;

use Illuminate\Database\Query\Builder;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

interface PermissionResolverInterface
{
    public function userHasPermission(
        int $userId,
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL,
        $teamIds = null
    ): bool;

    public function getTeamsWithPermissionForUser(
        int $userId,
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL
    );

    public function getAllAccessibleTeamsForUser(int $userId);

    public function getUserPermissionsOptimized(int $userId, $teamIds = null);

    public function clearRequestCache(): void;

    public function clearUserCache(int $userId): void;

    public function clearAllCache(): void;

    public function getCacheStats(): array;

    public function batchWarmCache(array $userIds): void;

    public function warmRolePermissions($role): void;

    public function getTeamsQueryWithPermissionForUser(
        int $userId,
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL,
        ?string $teamTableAlias = 'teams'
    ): Builder;

    public function getPerformanceMetrics(): array;
}
