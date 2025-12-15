<?php

namespace Kompo\Auth\Models\Teams;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\PermissionTeamRole;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Teams\PermissionResolver;
use Kompo\Auth\Teams\TeamHierarchyService;
use Kompo\Auth\Teams\CacheKeyBuilder;

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

        // return $this->getPermissionRequestCache($cacheKey, function () use ($permissionKey, $type, $teamIds) {
            return $this->getPermissionResolver()->userHasPermission(
                $this->id,
                $permissionKey,
                $type,
                $teamIds
            );
        // });
    }

    /**
     * Check if user has access to a specific team
     */
    public function hasAccessToTeam(int $teamId, string $roleId = null): bool
    {
        $cacheKey = "team_access_{$teamId}_" . ($roleId ?? 'any');

        return $this->getPermissionRequestCache($cacheKey, function () use ($teamId, $roleId) {
            $cacheKey = CacheKeyBuilder::userTeamAccess($this->id, $teamId, $roleId);
            $tags = CacheKeyBuilder::getTagsForCacheType(CacheKeyBuilder::USER_TEAM_ACCESS);
            
            return Cache::rememberWithTags(
                $tags,
                $cacheKey,
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
     * Get query builder for teams where user has specific permission
     * Returns a query that can be used in EXISTS, JOIN, or subquery contexts
     */
    public function getTeamsQueryWithPermission(
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL,
        ?string $teamTableAlias = 'teams'
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
    public function getAllAccessibleTeamIds($search = null, $limit = null)
    {
        if ($search) {
            return array_keys($this->getAllTeamIdsWithRolesCached(profile: null, search: $search, limit: $limit));
        }
        
        return Cache::rememberWithTags(
            ['permissions-v2'],
            "user_all_accessible_teams.{$this->id}",
            900,
            function () use ($limit) {
                return array_keys($this->getAllTeamIdsWithRolesCached(profile: null, search: null, limit: $limit)); 
            }   
        );
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
    public function getAllTeamIdsWithRolesCached($profile = 1, $search = '', $limit = null)
    {
        // Don't cache searches to avoid memory bloat
        if ($search) {
            return $this->getAllTeamIdsWithRoles($profile, $search, $limit);
        }

        return Cache::rememberWithTags(
            ['permissions-v2'],
            "allTeamIdsWithRoles.{$this->id}.{$profile}.{$limit}",
            180,
            fn() => $this->getAllTeamIdsWithRoles($profile, '', $limit)
        );
    }

    /**
     * Get team IDs with roles using optimized batch processing
     * Groups team roles by hierarchy type and processes each group efficiently
     */
    public function getAllTeamIdsWithRoles($profile = 1, $search = '', $limit = null)
    {
        $teamRoles = $this->activeTeamRoles()
            ->with(['roleRelation', 'team'])
            ->whereHas('roleRelation', fn($q) => $q->when($profile, fn($q) => $q->where('profile', $profile)))
            ->get();

        if ($teamRoles->isEmpty()) {
            return [];
        }

        $result = collect();
        $quantityLeft = $limit;
        $hierarchyService = app(TeamHierarchyService::class);

        // Group team roles by hierarchy type for batch processing
        $groupedTeamRoles = $this->groupTeamRolesByHierarchy($teamRoles);

        // Process each hierarchy group with batch operations
        foreach ($groupedTeamRoles as $hierarchyType => $roleGroup) {
            if ($quantityLeft !== null && $quantityLeft <= 0) {
                break; // Early termination when limit reached
            }

            $batchResults = $this->processBatchHierarchyGroup(
                $hierarchyType, 
                $roleGroup, 
                $search, 
                $quantityLeft,
                $hierarchyService
            );

            // Merge batch results into final result
            $result = $this->mergeBatchResults($result, $batchResults);

            if ($quantityLeft !== null) {
                $quantityLeft -= count($batchResults);
            }
        }

        return $result->all();
    }

    /**
     * Group team roles by their hierarchy access patterns
     * This enables efficient batch processing of similar hierarchy types
     */
    private function groupTeamRolesByHierarchy(Collection $teamRoles): array
    {
        $grouped = [
            'direct' => [],
            'below' => [],
            'neighbors' => [],
            'below_and_neighbors' => []
        ];

        foreach ($teamRoles as $teamRole) {
            $hasBelow = $teamRole->getRoleHierarchyAccessBelow();
            $hasNeighbors = $teamRole->getRoleHierarchyAccessNeighbors();

            if ($hasBelow && $hasNeighbors) {
                $grouped['below_and_neighbors'][] = $teamRole;
            } elseif ($hasBelow) {
                $grouped['below'][] = $teamRole;
            } elseif ($hasNeighbors) {
                $grouped['neighbors'][] = $teamRole;
            } else {
                $grouped['direct'][] = $teamRole;
            }
        }

        // Remove empty groups to avoid unnecessary processing
        return array_filter($grouped, fn($group) => !empty($group));
    }

    /**
     * Process a batch of team roles with the same hierarchy type
     * Uses efficient batch queries instead of individual lookups
     */
    private function processBatchHierarchyGroup(
        string $hierarchyType, 
        array $teamRoles, 
        string $search, 
        ?int $limit,
        TeamHierarchyService $hierarchyService
    ): Collection {
        $batchResults = collect();

        // Always include direct access (the team itself)
        $directTeams = $this->getDirectTeamAccess($teamRoles, $search);
        $batchResults = $batchResults->union($directTeams);

        // Apply hierarchy-specific batch operations
        switch ($hierarchyType) {
            case 'direct':
                // Only direct access, already handled above
                break;

            case 'below':
                $belowTeams = $this->getBatchDescendantAccess($teamRoles, $search, $limit, $hierarchyService);
                $batchResults = $batchResults->union($belowTeams);
                break;

            case 'neighbors':
                $neighborTeams = $this->getBatchNeighborAccess($teamRoles, $search, $limit, $hierarchyService);
                $batchResults = $batchResults->union($neighborTeams);
                break;

            case 'below_and_neighbors':
                $belowTeams = $this->getBatchDescendantAccess($teamRoles, $search, $limit, $hierarchyService);
                $neighborTeams = $this->getBatchNeighborAccess($teamRoles, $search, $limit, $hierarchyService);
                $batchResults = $batchResults->union($belowTeams)->union($neighborTeams);
                break;
        }

        return $batchResults->take($limit ?? PHP_INT_MAX);
    }

    /**
     * Get direct team access (the teams the roles are directly assigned to)
     */
    private function getDirectTeamAccess(array $teamRoles, string $search): Collection
    {
        $directTeams = collect();

        foreach ($teamRoles as $teamRole) {
            // Apply search filter if specified
            if ($search && !str_contains(strtolower($teamRole->team->team_name), strtolower($search))) {
                continue;
            }

            $teamId = $teamRole->team->id;
            $role = $teamRole->role;

            // Use the same merging logic as the original method
            if ($directTeams->has($teamId)) {
                $existingRoles = $directTeams->get($teamId);
                if (!in_array($role, $existingRoles)) {
                    $existingRoles[] = $role;
                    $directTeams->put($teamId, $existingRoles);
                }
            } else {
                $directTeams->put($teamId, [$role]);
            }
        }

        return $directTeams;
    }

    /**
     * Get descendant team access using batch operations
     */
    private function getBatchDescendantAccess(
        array $teamRoles, 
        string $search, 
        ?int $limit,
        TeamHierarchyService $hierarchyService
    ): Collection {
        // Prepare batch input: team_id => role mapping
        $teamIdsWithRoles = [];
        foreach ($teamRoles as $teamRole) {
            $teamIdsWithRoles[$teamRole->team->id] = $teamRole->role;
        }

        return $hierarchyService->getBatchDescendantTeamsWithRoles($teamIdsWithRoles, $search, $limit);
    }

    /**
     * Get neighbor team access using batch operations
     */
    private function getBatchNeighborAccess(
        array $teamRoles, 
        string $search, 
        ?int $limit,
        TeamHierarchyService $hierarchyService
    ): Collection {
        // Prepare batch input: team_id => role mapping
        $teamIdsWithRoles = [];
        foreach ($teamRoles as $teamRole) {
            $teamIdsWithRoles[$teamRole->team->id] = $teamRole->role;
        }

        return $hierarchyService->getBatchSiblingTeamsWithRoles($teamIdsWithRoles, $search, $limit);
    }

    /**
     * Merge batch results into the final result collection
     * Maintains the same logic as the original method for handling duplicate teams
     */
    private function mergeBatchResults(Collection $result, Collection $batchResults): Collection
    {
        foreach ($batchResults as $teamId => $roles) {
            if ($result->has($teamId)) {
                $existingRoles = $result->get($teamId);
                // Ensure we only add unique roles
                if (is_array($roles)) {
                    $newRoles = array_diff($roles, $existingRoles);
                    if (!empty($newRoles)) {
                        $result->put($teamId, array_merge($existingRoles, $newRoles));
                    }
                } else {
                    if (!in_array($roles, $existingRoles)) {
                        $existingRoles[] = $roles;
                        $result->put($teamId, $existingRoles);
                    }
                }
            } else {
                $result->put($teamId, is_array($roles) ? $roles : [$roles]);
            }
        }

        return $result;
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
                // Pre-warm critical caches using tags
                $teamRoleKey = CacheKeyBuilder::currentTeamRole($this->id);
                $teamRoleTags = CacheKeyBuilder::getTagsForCacheType(CacheKeyBuilder::CURRENT_TEAM_ROLE);
                Cache::tags($teamRoleTags)->put($teamRoleKey, $currentTeamRole, 900);

                $teamKey = CacheKeyBuilder::currentTeam($this->id);
                $teamTags = CacheKeyBuilder::getTagsForCacheType(CacheKeyBuilder::CURRENT_TEAM);
                Cache::tags($teamTags)->put($teamKey, $currentTeamRole->team, 900);

                $superAdminKey = CacheKeyBuilder::isSuperAdmin($this->id);
                $superAdminTags = CacheKeyBuilder::getTagsForCacheType(CacheKeyBuilder::IS_SUPER_ADMIN);
                Cache::tags($superAdminTags)->put($superAdminKey, $this->isSuperAdmin(), 900);

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
