<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kompo\Auth\Facades\UserModel;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Teams\Cache\AuthCacheLayer;
use Kompo\Auth\Teams\CacheKeyBuilder;
use Kompo\Auth\Teams\Contracts\PermissionResolverInterface;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;
use Kompo\Auth\Teams\Contracts\TeamRoleAccessResolverInterface;

/**
 * Centralized permission resolution service
 * Handles all permission checking logic with optimized caching and queries
 */
class PermissionResolver implements PermissionResolverInterface
{
    private TeamHierarchyInterface $hierarchyService;
    private ?AuthCacheLayer $cache;
    private ?TeamRoleAccessResolverInterface $teamRoleAccessResolver;
    private ?PermissionResolverInterface $publicApi = null;

    public function __construct(
        TeamHierarchyInterface $hierarchyService,
        ?AuthCacheLayer $cache = null,
        ?TeamRoleAccessResolverInterface $teamRoleAccessResolver = null,
    ) {
        $this->hierarchyService = $hierarchyService;
        $this->cache = $cache;
        $this->teamRoleAccessResolver = $teamRoleAccessResolver;
    }

    /**
     * Wire the public-facing API (typically the cache decorator) so internal
     * calls can route through cached variants even when the outer cache is cold.
     */
    public function setPublicApi(PermissionResolverInterface $publicApi): void
    {
        $this->publicApi = $publicApi;
    }

    /**
     * Return the public API (decorator) when wired, or $this otherwise.
     * Internal methods that have cached counterparts in the decorator should
     * route through this helper so nested calls benefit from sub-caches.
     */
    private function publicApi(): PermissionResolverInterface
    {
        return $this->publicApi ?? $this;
    }
    
    /**
     * Main permission checking method with optimized resolution
     */
    public function userHasPermission(
        int $userId, 
        string $permissionKey, 
        PermissionTypeEnum $type = PermissionTypeEnum::ALL, 
        $teamIds = null
    ): bool {
        if ($this->shouldBypassSecurity($userId)) {
            return true;
        }

        $userPermissions = collect($this->getUserPermissionsOptimized($userId, $teamIds));

        if ($this->hasExplicitDeny($userPermissions, $permissionKey)) {
            return false;
        }

        return $this->hasRequiredPermission($userPermissions, $permissionKey, $type);
    }

    /**
     * Get teams where user has specific permission
     */
    public function getTeamsWithPermissionForUser(
        int $userId,
        string $permissionKey, 
        PermissionTypeEnum $type = PermissionTypeEnum::ALL
    ) {
        return $this->getTeamSpecificPermissions($userId, $permissionKey, $type);
    }

    /**
     * Get all teams the user has any access to
     */
    public function getAllAccessibleTeamsForUser(int $userId)
    {
        if ($this->teamRoleAccessResolver) {
            $user = UserModel::find($userId);

            return $user ? $this->teamRoleAccessResolver->accessibleTeamIds($user) : [];
        }

        $teamRoles = $this->publicApi()->getUserActiveTeamRoles($userId);
        if ($teamRoles->isEmpty()) {
            return [];
        }

        $result = collect($teamRoles->pluck('team_id')->all())
            ->filter()
            ->unique();

        $grouped = $this->groupTeamRolesByHierarchy($teamRoles);

        foreach ($grouped as $type => $group) {
            $teamIds = collect($group)->pluck('team_id')->filter()->unique()->values()->all();
            if (empty($teamIds)) {
                continue;
            }

            if ($type === 'below' || $type === 'below_and_neighbors') {
                $descendants = $this->hierarchyService
                    ->getBatchDescendantTeamIdsByRoot($teamIds)
                    ->flatten();
                $result = $result->concat($descendants);
            }

            if ($type === 'neighbors' || $type === 'below_and_neighbors') {
                $siblings = $this->hierarchyService->getBatchSiblingTeamIds($teamIds);
                $result = $result->concat($siblings);
            }
        }

        return $result->unique()->values()->all();
    }

    /**
     * Group team roles by their hierarchy access patterns for batched processing.
     */
    private function groupTeamRolesByHierarchy(Collection $teamRoles): array
    {
        $grouped = [
            'direct' => [],
            'below' => [],
            'neighbors' => [],
            'below_and_neighbors' => [],
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

        return array_filter($grouped, fn($group) => !empty($group));
    }
    
    /**
     * Optimized user permissions retrieval with smart caching
     */
    public function getUserPermissionsOptimized(int $userId, $teamIds = null)
    {
        return $this->resolveUserPermissions($userId, $teamIds);
    }
    
    /**
     * Core permission resolution logic
     */
    private function resolveUserPermissions(int $userId, $teamIds = null)
    {
        // Get user's active team roles with optimized loading
        $teamRoles = $this->publicApi()->getUserActiveTeamRoles($userId, $teamIds);

        if ($teamRoles->isEmpty()) {
            return [];
        }

        $permissionData = $this->preloadPermissionData($teamRoles);

        // Resolve permissions with hierarchy consideration
        return $this->buildUserPermissionSet($teamRoles, $teamIds, $permissionData);
    }
    
    /**
     * Get user's active team roles with optimized queries
     */
    public function getUserActiveTeamRoles(int $userId, $teamIds = null): Collection
    {
        $query = TeamRole::with(['roleRelation', 'team'])
            ->where('user_id', $userId)
            ->whereHas('team')
            ->withoutGlobalScope('authUserHasPermissions');

        // Apply team filtering if specified
        if ($teamIds !== null) {
            $targetTeamIds = collect(is_iterable($teamIds) ? $teamIds : [$teamIds]);
            $accessibleTeamIds = $this->publicApi()->getAccessibleTeamIds($targetTeamIds);

            $query->whereIn('team_id', $accessibleTeamIds);
        }

        return $query->get();
    }

    /**
     * Get all team IDs that could grant access to target teams through hierarchy
     */
    public function getAccessibleTeamIds(Collection $targetTeamIds): Collection
    {
        $accessibleTeams = collect();
        
        // Add target teams themselves
        $accessibleTeams = $accessibleTeams->concat($targetTeamIds)->filter();
        $targetTeamIds = $targetTeamIds->unique()->filter();

        $accessibleTeams = $accessibleTeams
            ->concat($this->hierarchyService->getBatchAncestorTeamIds($targetTeamIds->all()))
            ->concat($this->hierarchyService->getBatchSiblingTeamIds($targetTeamIds->all()))
            ->filter();

        return $accessibleTeams->unique();
    }
    
    /**
     * Preload all permission-related data in batches
     */
    private function preloadPermissionData(Collection $teamRoles): array
    {
        $roleIds = $teamRoles->pluck('role')->unique();
        $teamRoleIds = $teamRoles->pluck('id');
        $permissionData = [
            'roles' => [],
            'team_roles' => [],
        ];

        // Batch load role permissions
        if ($roleIds->isNotEmpty()) {
            $rolePermissions = DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->whereIn('permission_role.role', $roleIds)
                ->selectRaw(constructComplexPermissionKeySql('permission_role'). ', permission_role.role as role')
                ->get()
                ->groupBy('role');

            foreach ($rolePermissions as $roleId => $permissions) {
                $perms = collect($permissions)->pluck('complex_permission_key')->all();
                $permissionData['roles'][$roleId] = $perms;
                $this->cache?->put(
                    CacheKeyBuilder::rolePermissions($roleId),
                    CacheKeyBuilder::ROLE_PERMISSIONS,
                    $perms
                );
            }
        }

        // Batch load team role permissions
        if ($teamRoleIds->isNotEmpty()) {
            $teamRolePermissions = DB::table('permission_team_role')
                ->join('permissions', 'permissions.id', '=', 'permission_team_role.permission_id')
                ->whereIn('permission_team_role.team_role_id', $teamRoleIds)
                ->selectRaw(constructComplexPermissionKeySql('permission_team_role'). ', permission_team_role.team_role_id as team_role_id')
                ->get()
                ->groupBy('team_role_id');
                
            foreach ($teamRolePermissions as $teamRoleId => $permissions) {
                $perms = collect($permissions)->pluck('complex_permission_key')->all();
                $permissionData['team_roles'][$teamRoleId] = $perms;
                $this->cache?->put(
                    CacheKeyBuilder::teamRolePermissions($teamRoleId),
                    CacheKeyBuilder::TEAM_ROLE_PERMISSIONS,
                    $perms
                );
            }
        }

        return $permissionData;
    }
    
    /**
     * Build the complete permission set for a user
     */
    private function buildUserPermissionSet(Collection $teamRoles, $teamIds = null, array $permissionData = [])
    {
        $permissions = collect();

        foreach ($teamRoles as $teamRole) {
            // Get teams this role has access to
            $accessibleTeams = collect($this->publicApi()->getTeamRoleAccessibleTeams($teamRole));

            // Filter by target teams if specified
            if ($teamIds !== null) {
                $targetTeams = collect(is_iterable($teamIds) ? $teamIds : [$teamIds]);
                $accessibleTeams = $accessibleTeams->intersect($targetTeams);
            }

            if ($accessibleTeams->isEmpty()) {
                continue;
            }

            // Add role-based permissions
            $rolePermissions = $permissionData['roles'][$teamRole->role] ?? $this->publicApi()->getRolePermissions($teamRole->roleRelation);
            $permissions = $permissions->concat($rolePermissions);

            // Add direct team role permissions
            $directPermissions = $permissionData['team_roles'][$teamRole->id] ?? $this->publicApi()->getTeamRolePermissions($teamRole);
            $permissions = $permissions->concat($directPermissions);
        }
        
        return $permissions->unique()->all();
    }
    
    /**
     * Get teams accessible through a team role (considering hierarchy)
     */
    public function getTeamRoleAccessibleTeams(TeamRole $teamRole): array
    {
        $teams = collect([$teamRole->team_id]);

        if ($teamRole->getRoleHierarchyAccessBelow()) {
            $teams = $teams->concat($this->hierarchyService->getDescendantTeamIds($teamRole->team_id));
        }

        if ($teamRole->getRoleHierarchyAccessNeighbors()) {
            $teams = $teams->concat($this->hierarchyService->getSiblingTeamIds($teamRole->team_id));
        }

        return $teams->unique()->values()->all();
    }

    /**
     * Get permissions from role with efficient query
     */
    public function getRolePermissions($role): array
    {
        if (!$role) {
            return [];
        }

        return $role->permissions()->selectRaw(constructComplexPermissionKeySql('permission_role'))
            ->pluck('complex_permission_key')->all();
    }

    public function warmRolePermissions($role): void
    {
        $this->getRolePermissions($role);
    }
    
    /**
     * Get direct team role permissions
     */
    public function getTeamRolePermissions(TeamRole $teamRole): array
    {
        return $teamRole->permissions()
            ->selectRaw(constructComplexPermissionKeySql('permission_team_role'))
            ->pluck('complex_permission_key')->all();
    }

    /**
     * Get teams where user has specific permission (not global)
     */
    private function getTeamSpecificPermissions(int $userId, string $permissionKey, PermissionTypeEnum $type)
    {
        $teamsWithPermission = collect();
        $teamRoles = $this->publicApi()->getUserActiveTeamRoles($userId);
        $permissionData = $this->preloadPermissionData($teamRoles);

        foreach ($teamRoles as $teamRole) {
            if ($this->teamRoleHasPermission($teamRole, $permissionKey, $type, $permissionData)) {
                $accessibleTeams = $this->publicApi()->getTeamRoleAccessibleTeams($teamRole);
                $teamsWithPermission = $teamsWithPermission->concat($accessibleTeams);
            }
        }

        return $teamsWithPermission->unique()->values()->all();
    }
    
    /**
     * Check if team role has specific permission (optimized)
     */
    private function teamRoleHasPermission(
        TeamRole $teamRole, 
        string $permissionKey, 
        PermissionTypeEnum $type,
        array $permissionData = []
    ): bool {
        // Check role permissions first (most common case)
        if ($teamRole->roleRelation) {
            $rolePermissions = $permissionData['roles'][$teamRole->role] ?? $this->getRolePermissions($teamRole->roleRelation);

            foreach ($rolePermissions as $permission) {
                if (getPermissionKey($permission) === $permissionKey && 
                    getPermissionType($permission) !== PermissionTypeEnum::DENY &&
                    PermissionTypeEnum::hasPermission(getPermissionType($permission), $type)) {
                    return true;
                }
            }
        }
        
        // Check direct team role permissions
        $directPermissions = $permissionData['team_roles'][$teamRole->id] ?? $this->getTeamRolePermissions($teamRole);
        
        foreach ($directPermissions as $permission) {
            if (getPermissionKey($permission) === $permissionKey && 
                getPermissionType($permission) !== PermissionTypeEnum::DENY &&
                PermissionTypeEnum::hasPermission(getPermissionType($permission), $type)) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Check for explicit deny permissions
     */
    public function hasExplicitDeny(Collection $permissions, string $permissionKey): bool
    {
        return $permissions->contains(function($permission) use ($permissionKey) {
            return getPermissionKey($permission) === $permissionKey && 
                   getPermissionType($permission) === PermissionTypeEnum::DENY;
        });
    }
    
    /**
     * Check if user has required permission type
     */
    public function hasRequiredPermission(
        Collection $permissions, 
        string $permissionKey, 
        PermissionTypeEnum $requiredType
    ): bool {
        $userPermissions = $permissions->filter(function($permission) use ($permissionKey) {
            return getPermissionKey($permission) === $permissionKey;
        })->all();
        
        foreach ($userPermissions as $permission) {
            if (PermissionTypeEnum::hasPermission(getPermissionType($permission), $requiredType)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if security should be bypassed
     */
    private function shouldBypassSecurity(int $userId): bool
    {
        // Check global bypass
        if (globalSecurityBypass()) {
            return true;
        }
        
        return $this->userIsSuperAdmin($userId);
    }

    public function userIsSuperAdmin(int $userId): bool
    {
        $user = UserModel::find($userId);

        return $user && $user->isSuperAdmin();
    }
    
    /**
     * Compatibility shim for callers still resolving the concrete service.
     */
    public function clearRequestCache(): void
    {
        if (app()->bound(\Kompo\Auth\Teams\Cache\AuthCacheLayer::class)) {
            app(\Kompo\Auth\Teams\Cache\AuthCacheLayer::class)->flushRequestCache();
        }
    }
    
    /**
     * Compatibility shim for callers still resolving the concrete service.
     */
    public function clearUserCache(int $userId): void
    {
        if (app()->bound(\Kompo\Auth\Teams\Contracts\PermissionResolverInterface::class)) {
            app(\Kompo\Auth\Teams\Contracts\PermissionResolverInterface::class)->clearUserCache($userId);
        }
    }
    
    /**
     * Compatibility shim for callers still resolving the concrete service.
     */
    public function clearAllCache(): void
    {
        if (app()->bound(\Kompo\Auth\Teams\Contracts\PermissionResolverInterface::class)) {
            app(\Kompo\Auth\Teams\Contracts\PermissionResolverInterface::class)->clearAllCache();
        }
    }
    
    /**
     * Get cache statistics for monitoring
     */
    public function getCacheStats(): array
    {
        if (app()->bound(\Kompo\Auth\Teams\Cache\AuthCacheLayer::class)) {
            return app(\Kompo\Auth\Teams\Cache\AuthCacheLayer::class)->stats();
        }

        return [];
    }
    
    /**
     * Batch warm cache for multiple users
     */
    public function batchWarmCache(array $userIds): void
    {
        foreach ($userIds as $userId) {
            try {
                // Pre-load basic permissions
                $this->getUserPermissionsOptimized($userId);
                
                // Pre-load accessible teams
                $this->getAllAccessibleTeamsForUser($userId);
                
            } catch (\Exception $e) {
                \Log::warning("Failed to warm cache for user {$userId}: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Get query builder for teams where user has specific permission
     * Returns a query that can be used in EXISTS, JOIN, or subquery contexts
     */
    public function getTeamsQueryWithPermissionForUser(
        int $userId,
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL,
        ?string $teamTableAlias = 'teams'
    ): \Illuminate\Database\Query\Builder {
        // Base query for teams
        $query = DB::table($teamTableAlias ?: 'teams')
            ->select(($teamTableAlias ?: 'teams') . '.id');

        $query->where(function ($q) use ($userId, $permissionKey, $type, $teamTableAlias) {
            // First, exclude teams where user has explicit DENY for this permission
            $q->whereNotExists(function ($denyQuery) use ($userId, $permissionKey, $teamTableAlias) {
                $denyQuery->select(DB::raw(1))
                    ->from('team_roles as tr_deny')
                    ->whereColumn('tr_deny.team_id', ($teamTableAlias ?: 'teams') . '.id')
                    ->where('tr_deny.user_id', $userId)
                    ->whereNull('tr_deny.terminated_at')
                    ->whereNull('tr_deny.suspended_at')
                    ->where(function ($denySubQuery) use ($permissionKey) {
                        // Check for DENY in role-based permissions
                        $denySubQuery->whereExists(function ($roleDenyQuery) use ($permissionKey) {
                            $roleDenyQuery->select(DB::raw(1))
                                ->from('permission_role as pr_deny')
                                ->join('permissions as p_deny', 'pr_deny.permission_id', '=', 'p_deny.id')
                                ->whereColumn('pr_deny.role', 'tr_deny.role')
                                ->where('p_deny.permission_key', $permissionKey)
                                ->where('pr_deny.permission_type', PermissionTypeEnum::DENY->value);
                        })
                        // Check for DENY in direct team role permissions
                        ->orWhereExists(function ($directDenyQuery) use ($permissionKey) {
                            $directDenyQuery->select(DB::raw(1))
                                ->from('permission_team_role as ptr_deny')
                                ->join('permissions as p_deny', 'ptr_deny.permission_id', '=', 'p_deny.id')
                                ->whereColumn('ptr_deny.team_role_id', 'tr_deny.id')
                                ->where('p_deny.permission_key', $permissionKey)
                                ->where('ptr_deny.permission_type', PermissionTypeEnum::DENY->value);
                        });
                    });
            });

            // Then, include teams where user has the required permission
            $q->where(function ($allowQuery) use ($userId, $permissionKey, $type, $teamTableAlias) {
                // Query for role-based permissions
                $allowQuery->whereExists(function ($roleQuery) use ($userId, $permissionKey, $type, $teamTableAlias) {
                    $roleQuery->select(DB::raw(1))
                        ->from('team_roles as tr')
                        ->join('permission_role as pr', 'tr.role', '=', 'pr.role')
                        ->join('permissions as p', 'pr.permission_id', '=', 'p.id')
                        ->whereColumn('tr.team_id', ($teamTableAlias ?: 'teams') . '.id')
                        ->where('tr.user_id', $userId)
                        ->whereNull('tr.terminated_at')
                        ->whereNull('tr.suspended_at')
                        ->where('p.permission_key', $permissionKey)
                        ->where('pr.permission_type', '!=', PermissionTypeEnum::DENY->value);

                    // Apply permission type filter
                    if ($type !== PermissionTypeEnum::ALL) {
                        $roleQuery->where(function ($typeQuery) use ($type) {
                            $typeQuery->where('pr.permission_type', $type->value)
                                ->orWhere('pr.permission_type', PermissionTypeEnum::ALL->value);
                        });
                    }
                });

                // Query for direct team role permissions
                $allowQuery->orWhereExists(function ($directQuery) use ($userId, $permissionKey, $type, $teamTableAlias) {
                    $directQuery->select(DB::raw(1))
                        ->from('team_roles as tr')
                        ->join('permission_team_role as ptr', 'tr.id', '=', 'ptr.team_role_id')
                        ->join('permissions as p', 'ptr.permission_id', '=', 'p.id')
                        ->whereColumn('tr.team_id', ($teamTableAlias ?: 'teams') . '.id')
                        ->where('tr.user_id', $userId)
                        ->whereNull('tr.terminated_at')
                        ->whereNull('tr.suspended_at')
                        ->where('p.permission_key', $permissionKey)
                        ->where('ptr.permission_type', '!=', PermissionTypeEnum::DENY->value);

                    // Apply permission type filter
                    if ($type !== PermissionTypeEnum::ALL) {
                        $directQuery->where(function ($typeQuery) use ($type) {
                            $typeQuery->where('ptr.permission_type', $type->value)
                                ->orWhere('ptr.permission_type', PermissionTypeEnum::ALL->value);
                        });
                    }
                });
            });
        });

        return $query;
    }

    /**
     * Get performance metrics for debugging
     */
    public function getPerformanceMetrics(): array
    {
        $metrics = [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];

        if (app()->bound(\Kompo\Auth\Teams\Cache\AuthCacheLayer::class)) {
            $metrics['cache_layer'] = app(\Kompo\Auth\Teams\Cache\AuthCacheLayer::class)->stats();
        }

        return $metrics;
    }
}
