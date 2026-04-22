<?php

namespace Kompo\Auth\Teams\Contracts;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\TeamRole;

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

    public function getUserActiveTeamRoles(int $userId, $teamIds = null): Collection;

    public function getRolePermissions($role): array;

    public function getTeamRolePermissions(TeamRole $teamRole): array;

    public function getTeamRoleAccessibleTeams(TeamRole $teamRole): array;

    public function getAccessibleTeamIds(Collection $targetTeamIds): Collection;

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
        ?string $teamTableAlias = null
    ): Builder;

    public function getUsersQueryWithPermission(
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL,
        $teamIds = null,
        ?string $usersTableAlias = null
    ): Builder;

    public function getPerformanceMetrics(): array;
}
