<?php

namespace Kompo\Auth\Models\Teams;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\PermissionTeamRole;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Teams\PermissionResolver;

/**
 * Unified permissions trait that handles all permission logic
 * Uses the optimized PermissionResolver service under the hood
 */
trait HasTeamPermissions
{
    /**
     * Request-level cache to avoid repeated service calls
     */
    private array $permissionRequestCache = [];

    /**
     * Get the permission resolver service
     */
    private function getPermissionResolver(): PermissionResolver
    {
        return app(PermissionResolver::class);
    }

    /**
     * Clear request-level permission cache
     */
    public function clearPermissionRequestCache(): void
    {
        $this->permissionRequestCache = [];
    }

    /**
     * Get cached data for current request to avoid repeated service calls
     */
    private function getPermissionRequestCache(string $key, callable $callback = null)
    {
        if (!isset($this->permissionRequestCache[$key])) {
            if ($callback) {
                $this->permissionRequestCache[$key] = $callback();
            } else {
                return null;
            }
        }

        return $this->permissionRequestCache[$key];
    }

    /**
     * Core permission checking method - delegates to PermissionResolver
     */
    public function hasPermission(
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL,
        $teamIds = null
    ): bool {
        $cacheKey = "permission_{$permissionKey}_{$type->value}_" . md5(serialize($teamIds));

        return $this->getPermissionRequestCache($cacheKey, function () use ($permissionKey, $type, $teamIds) {
            return $this->getPermissionResolver()->userHasPermission(
                $this->id,
                $permissionKey,
                $type,
                $teamIds
            );
        });
    }

    /**
     * Check if user has access to a specific team
     */
    public function hasAccessToTeam(int $teamId, string $roleId = null): bool
    {
        $cacheKey = "team_access_{$teamId}_" . ($roleId ?? 'any');

        return $this->getPermissionRequestCache($cacheKey, function () use ($teamId, $roleId) {
            return Cache::rememberWithTags(
                ['permissions-v2'],
                "user_team_access.{$this->id}.{$teamId}." . ($roleId ?? 'any'),
                900,
                function () use ($teamId, $roleId) {
                    $teamRoles = $this->getActiveTeamRolesOptimized($roleId);

                    return $teamRoles->some(function ($teamRole) use ($teamId) {
                        return $teamRole->hasAccessToTeam($teamId);
                    });
                }
            );
        });
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
     * Get all team IDs user has access to
     */
    public function getAllAccessibleTeamIds()
    {
        return Cache::rememberWithTags(
            ['permissions-v2'],
            "user_all_accessible_teams.{$this->id}",
            900,
            function () {
                $accessibleTeams = collect();
                $teamRoles = $this->getActiveTeamRolesOptimized();

                foreach ($teamRoles as $teamRole) {
                    $teams = $teamRole->getAccessibleTeamsOptimized();
                    $accessibleTeams = $accessibleTeams->concat($teams);
                }

                return $accessibleTeams->unique()->values()->all();
            }
        );
    }

    /**
     * Get active team roles with optimized loading
     */
    private function getActiveTeamRolesOptimized(string $roleId = null): Collection
    {
        return Cache::rememberWithTags(
            ['permissions-v2'],
            "activeTeamRoles.{$this->id}.{$roleId}",
            900,
            function () use ($roleId) {
                return $this->activeTeamRoles()
                    ->when($roleId, fn($q) => $q->where('role', $roleId))
                    ->with(['team:id,team_name,parent_team_id', 'roleRelation:id,name,profile'])
                    ->get();
            }
        );
    }

    /**
     * Get all team IDs with roles (optimized for role switcher)
     */
    public function getAllTeamIdsWithRolesCached($profile = 1, $search = '')
    {
        // Don't cache searches to avoid memory bloat
        if ($search) {
            return $this->getAllTeamIdsWithRoles($profile, $search);
        }

        return Cache::rememberWithTags(
            ['permissions-v2'],
            "allTeamIdsWithRoles.{$this->id}.{$profile}",
            180,
            fn() => $this->getAllTeamIdsWithRoles($profile, '')
        );
    }

    /**
     * Get team IDs with roles (base implementation)
     */
    public function getAllTeamIdsWithRoles($profile = 1, $search = '')
    {
        $teamRoles = $this->activeTeamRoles()
            ->with(['roleRelation', 'team'])
            ->whereHas('roleRelation', fn($q) => $q->where('profile', $profile))
            ->get();

        $result = collect();

        foreach ($teamRoles as $teamRole) {
            $hierarchyTeams = $teamRole->getAllHierarchyTeamsIds($search);

            // Merge the hierarchy teams, grouping roles by team_id
            foreach ($hierarchyTeams as $teamId => $role) {
                if ($result->has($teamId)) {
                    // If team already exists, add the role to the array
                    $existingRoles = $result->get($teamId);
                    if (!in_array($role, $existingRoles)) {
                        $existingRoles[] = $role;
                        $result->put($teamId, $existingRoles);
                    }
                } else {
                    // If team doesn't exist, create new entry with role array
                    $result->put($teamId, [$role]);
                }
            }
        }

        return $result->all();
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
     * Get current permissions in all teams (legacy method for compatibility)
     */
    public function getCurrentPermissionsInAllTeams(): Collection
    {
        return $this->getPermissionRequestCache('current_permissions_all_teams', function () {
            return Cache::rememberWithTags(
                ['permissions-v2'],
                'currentPermissionsInAllTeams' . $this->id,
                900,
                fn() => TeamRole::getAllPermissionsKeysForMultipleRoles($this->activeTeamRoles)
            );
        });
    }

    /**
     * Get current permission keys in specific teams (legacy method for compatibility)
     */
    public function getCurrentPermissionKeysInTeams($teamIds): Collection
    {
        $teamIds = collect(is_iterable($teamIds) ? $teamIds : [$teamIds]);
        $cacheKey = 'current_permission_keys_' . md5($teamIds->implode(','));

        return $this->getPermissionRequestCache($cacheKey, function () use ($teamIds) {
            return Cache::rememberWithTags(
                ['permissions-v2'],
                'currentPermissionKeys' . $this->id . '|' . $teamIds->implode(','),
                900,
                fn() => TeamRole::getAllPermissionsKeysForMultipleRoles(
                    $this->activeTeamRoles->filter(fn($tr) => $tr->hasAccessToTeamOfMany($teamIds))
                )
            );
        });
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
                // Pre-warm critical caches
                Cache::put('currentTeamRole' . $this->id, $currentTeamRole, 900);
                Cache::put('currentTeam' . $this->id, $currentTeamRole->team, 900);
                Cache::put('isSuperAdmin' . $this->id, $this->isSuperAdmin(), 900);

                // Pre-load permissions asynchronously if possible
                if (config('queue.default') !== 'sync') {
                    dispatch(function () {
                        app(\Kompo\Auth\Teams\PermissionCacheManager::class)->warmUserCache($this->id);
                    })->afterResponse();
                } else {
                    // Synchronous pre-loading
                    app(\Kompo\Auth\Teams\PermissionCacheManager::class)->warmUserCache($this->id);
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

        // Pre-warm permission cache for all users
        $cacheManager = app(\Kompo\Auth\Teams\PermissionCacheManager::class);
        foreach ($userIds as $userId) {
            try {
                $cacheManager->warmUserCache($userId);
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
        return [
            'request_cache_size' => count($this->permissionRequestCache),
            'request_cache_keys' => array_keys($this->permissionRequestCache),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }
}
