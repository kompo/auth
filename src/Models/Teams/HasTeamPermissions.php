<?php

namespace Kompo\Auth\Models\Teams;

use Illuminate\Support\Collection;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\PermissionTeamRole;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Teams\Cache\AuthCacheLayer;
use Kompo\Auth\Teams\Cache\UserContextCache;
use Kompo\Auth\Teams\Cache\UserTeamCache;
use Kompo\Auth\Teams\Contracts\PermissionResolverInterface;
use Kompo\Auth\Teams\Contracts\TeamRoleAccessResolverInterface;

/**
 * Unified permissions trait that handles all permission logic
 * Uses the optimized PermissionResolver service under the hood
 */
trait HasTeamPermissions
{
    /**
     * Get the permission resolver service
     */
    private function getPermissionResolver(): PermissionResolverInterface
    {
        return app(PermissionResolverInterface::class);
    }

    private function getUserTeamCache(): UserTeamCache
    {
        return app(UserTeamCache::class);
    }

    private function getUserContextCache(): UserContextCache
    {
        return app(UserContextCache::class);
    }

    private function getAuthCacheLayer(): AuthCacheLayer
    {
        return app(AuthCacheLayer::class);
    }

    private function getTeamRoleAccessResolver(): TeamRoleAccessResolverInterface
    {
        return app(TeamRoleAccessResolverInterface::class);
    }

    /**
     * Clear request-level permission cache
     */
    public function clearPermissionRequestCache(): void
    {
        $this->getAuthCacheLayer()->flushRequestCache();
    }

    /**
     * Core permission checking method - delegates to PermissionResolver
     */
    public function hasPermission(
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL,
        $teamIds = null
    ): bool {
        return $this->getPermissionResolver()->userHasPermission(
            $this->id,
            $permissionKey,
            $type,
            $teamIds
        );
    }

    /**
     * Check if user has access to a specific team
     */
    public function hasAccessToTeam(int $teamId, string $roleId = null): bool
    {
        return $this->getTeamRoleAccessResolver()->hasAccessToTeam($this, $teamId, $roleId);
    }

    /**
     * Get teams where user has specific permission
     */
    public function getTeamsIdsWithPermission(
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL
    ): Collection {
        return collect($this->getPermissionResolver()->getTeamsWithPermissionForUser(
            $this->id,
            $permissionKey,
            $type
        ));
    }

    /**
     * Get query builder for teams where user has specific permission
     * Returns a query that can be used in EXISTS, JOIN, or subquery contexts
     */
    public function getTeamsQueryWithPermission(
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL,
        ?string $teamTableAlias = null
    ): \Illuminate\Database\Query\Builder {
        return $this->getPermissionResolver()->getTeamsQueryWithPermissionForUser(
            $this->id,
            $permissionKey,
            $type,
            $teamTableAlias
        );
    }

    /**
     * Get all team IDs user has access to
     */
    public function getAllAccessibleTeamIds($search = null)
    {
        return $this->getTeamRoleAccessResolver()->accessibleTeamIds($this, null, $search);
    }

    public function hasRole(string $role)
    {
        return $this->getActiveTeamRolesOptimized($role)->count() > 0;
    }

    /**
     * Get active team roles with optimized loading
     */
    private function getActiveTeamRolesOptimized(string $roleId = null): Collection
    {
        return $this->getUserTeamCache()->activeTeamRoles(
            $this->id,
            $roleId,
            function () use ($roleId) {
                return $this->activeTeamRoles()
                    ->when($roleId, fn($q) => $q->where('role', $roleId))
                    ->with(['team:id,team_name,parent_team_id', 'roleRelation:id,name,profile'])
                    ->get();
            }
        );
    }

    /**
     * Clear this user's permission cache
     */
    public function clearPermissionCache(): void
    {
        $this->getPermissionResolver()->clearUserCache($this->id);
        $this->clearPermissionRequestCache();
    }

    /**
     * Give permission to user (legacy method for compatibility)
     */
    public function givePermissionTo(string $permissionKey, $teamRoleId = null)
    {
        $permission = Permission::findByKey($permissionKey);
        if (!$permission) {
            throw new \InvalidArgumentException("Permission '{$permissionKey}' not found");
        }

        return $this->givePermissionId($permission->id, $teamRoleId);
    }

    /**
     * Give permission by ID (legacy method for compatibility)
     */
    public function givePermissionId(int $permissionId, $teamRoleId = null)
    {
        $teamRoleId = $teamRoleId ?: $this->current_team_role_id;

        $permissionTeamRole = PermissionTeamRole::forPermission($permissionId)->forTeamRole($teamRoleId)->first();

        if (!$permissionTeamRole) {
            $permissionTeamRole = new PermissionTeamRole();
            $permissionTeamRole->team_role_id = $teamRoleId;
            $permissionTeamRole->permission_id = $permissionId;
            $permissionTeamRole->save();
        }

        $this->clearPermissionCache();

        return $permissionTeamRole;
    }

    /**
     * Refresh roles and permissions cache with optimized warming
     */
    public function refreshRolesAndPermissionsCache(): void
    {
        // Clear old cache first
        $this->clearPermissionCache();

        try {
            $currentTeamRole = $this->currentTeamRole()->first();

            if ($currentTeamRole) {
                $contextCache = $this->getUserContextCache();
                $contextCache->putCurrentTeamRole($this->id, $currentTeamRole);
                $contextCache->putCurrentTeam($this->id, $currentTeamRole->team);
                $contextCache->putIsSuperAdmin($this->id, $this->isSuperAdmin());

                // Pre-load permissions asynchronously if possible
                if (config('queue.default') !== 'sync') {
                    dispatch(function () {
                        app(PermissionResolverInterface::class)->batchWarmCache([$this->id]);
                    })->afterResponse();
                } else {
                    // Synchronous pre-loading
                    app(PermissionResolverInterface::class)->batchWarmCache([$this->id]);
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to refresh roles and permissions cache: ' . $e->getMessage(), [
                'user_id' => $this->id,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Batch load data for multiple users (static method)
     */
    public static function batchLoadUserPermissions(Collection $users): void
    {
        if ($users->isEmpty()) {
            return;
        }

        $userIds = $users->pluck('id');

        // Pre-load team roles
        TeamRole::with(['team', 'roleRelation', 'permissions'])
            ->whereIn('user_id', $userIds)
            ->whereNull('terminated_at')
            ->whereNull('suspended_at')
            ->withoutGlobalScope('authUserHasPermissions')
            ->get()
            ->groupBy('user_id');

        // Pre-warm permission data through the resolver contract.
        $resolver = app(PermissionResolverInterface::class);
        foreach ($userIds as $userId) {
            try {
                $resolver->batchWarmCache([$userId]);
            } catch (\Throwable $e) {
                \Log::warning("Failed to pre-warm permissions for user {$userId}: " . $e->getMessage());
            }
        }
    }

    /**
     * Get memory usage for debugging
     */
    public function getPermissionMemoryUsage(): array
    {
        $cacheStats = $this->getAuthCacheLayer()->stats();

        return [
            'request_cache_size' => $cacheStats['request_cache_keys'] ?? 0,
            'request_cache_memory' => $cacheStats['request_cache_memory'] ?? 0,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }
}
