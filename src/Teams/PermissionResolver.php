<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\Roles\Role;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Teams\TeamHierarchyService;

/**
 * Centralized permission resolution service
 * Handles all permission checking logic with optimized caching and queries
 */
class PermissionResolver
{
    private const CACHE_TTL = 900; // 15 minutes
    private const CACHE_TAG = 'permissions-v2';
    private const MAX_TEAMS_PER_QUERY = 50;
    
    private TeamHierarchyService $hierarchyService;
    
    /**
     * In-memory cache for current request to avoid repeated database calls
     */
    private array $requestCache = [];
    
    public function __construct(TeamHierarchyService $hierarchyService)
    {
        $this->hierarchyService = $hierarchyService;
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
        // Early bypass checks
        if ($this->shouldBypassSecurity($userId)) {
            return true;
        }

        // Get user's permission cache
        $userPermissions = collect($this->getUserPermissionsOptimized($userId, $teamIds));

        // Check for explicit DENY first (highest priority)
        if ($this->hasExplicitDeny($userPermissions, $permissionKey)) {
            return false;
        }
        
        // Check for required permission
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
        $cacheKey = "user_teams_with_permission.{$userId}.{$permissionKey}.{$type->value}";
        
        return $this->getRequestCache($cacheKey, function() use ($userId, $permissionKey, $type, $cacheKey) {
            return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL, function() use ($userId, $permissionKey, $type) {
                // Check for global permission first
                if ($this->userHasPermission($userId, $permissionKey, $type)) {
                    // If user has global access, return all accessible teams
                    return $this->getAllAccessibleTeamsForUser($userId);
                }
                
                // Otherwise, check team-specific permissions
                return $this->getTeamSpecificPermissions($userId, $permissionKey, $type);
            });
        });
    }

    /**
     * Get all teams the user has any access to
     */
    public function getAllAccessibleTeamsForUser(int $userId)
    {
        $cacheKey = "user_all_accessible_teams.{$userId}";
        
        return $this->getRequestCache($cacheKey, function() use ($userId, $cacheKey) {
            return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL, function() use ($userId) {
                $accessibleTeams = collect();
                $teamRoles = $this->getUserActiveTeamRoles($userId);
                
                // Use batch processing for efficiency
                $this->preloadPermissionData($teamRoles);
                
                foreach ($teamRoles as $teamRole) {
                    $teams = $this->getTeamRoleAccessibleTeams($teamRole);
                    $accessibleTeams = $accessibleTeams->concat($teams);
                }
                
                return $accessibleTeams->unique()->values()->all();
            });
        });
    }
    
    /**
     * Optimized user permissions retrieval with smart caching
     */
    public function getUserPermissionsOptimized(int $userId, $teamIds = null)
    {
        $cacheKey = $this->buildPermissionCacheKey($userId, $teamIds);
        
        return $this->getRequestCache($cacheKey, function() use ($userId, $teamIds, $cacheKey) {
            return Cache::rememberWithTags(
                [self::CACHE_TAG],
                $cacheKey, 
                self::CACHE_TTL, 
                fn() => $this->resolveUserPermissions($userId, $teamIds)
            );
        });
    }
    
    /**
     * Core permission resolution logic
     */
    private function resolveUserPermissions(int $userId, $teamIds = null)
    {
        // Get user's active team roles with optimized loading
        $teamRoles = $this->getUserActiveTeamRoles($userId, $teamIds);
        
        if ($teamRoles->isEmpty()) {
            return [];
        }

        // Batch load all related data
        $this->preloadPermissionData($teamRoles);

        // Resolve permissions with hierarchy consideration
        return $this->buildUserPermissionSet($teamRoles, $teamIds);
    }
    
    /**
     * Get user's active team roles with optimized queries
     */
    private function getUserActiveTeamRoles(int $userId, $teamIds = null): Collection
    {
        $cacheKey = "user_active_team_roles.{$userId}." . md5(serialize($teamIds));
        
        return $this->getRequestCache($cacheKey, function() use ($userId, $teamIds) {
            $query = TeamRole::with(['roleRelation', 'team'])
                ->where('user_id', $userId)
                ->whereHas('team')
                ->withoutGlobalScope('authUserHasPermissions');
                
            // Apply team filtering if specified
            if ($teamIds !== null) {
                $targetTeamIds = collect(is_iterable($teamIds) ? $teamIds : [$teamIds]);
                
                // Get all teams that could grant access to target teams
                $accessibleTeamIds = $this->getAccessibleTeamIds($userId, $targetTeamIds);
                
                $query->whereIn('team_id', $accessibleTeamIds);
            }
            
            return $query->get();
        });
    }
    
    /**
     * Get all team IDs that could grant access to target teams through hierarchy
     */
    private function getAccessibleTeamIds(int $userId, Collection $targetTeamIds): Collection
    {
        $cacheKey = "accessible_teams.{$userId}." . $targetTeamIds->sort()->implode(',');
        
        return $this->getRequestCache($cacheKey, function() use ($userId, $targetTeamIds, $cacheKey) {
            return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL, function() use ($targetTeamIds) {
                $accessibleTeams = collect();
                
                // Add target teams themselves
                $accessibleTeams = $accessibleTeams->concat($targetTeamIds);
                
                // Add parent teams that could have hierarchy access
                foreach ($targetTeamIds as $teamId) {
                    $ancestors = $this->hierarchyService->getAncestorTeamIds($teamId);
                    $accessibleTeams = $accessibleTeams->concat($ancestors);
                    
                    $siblings = $this->hierarchyService->getSiblingTeamIds($teamId);
                    $accessibleTeams = $accessibleTeams->concat($siblings);
                }
                
                return $accessibleTeams->unique();
            });
        });
    }
    
    /**
     * Preload all permission-related data in batches
     */
    private function preloadPermissionData(Collection $teamRoles): void
    {
        $roleIds = $teamRoles->pluck('role')->unique();
        $teamRoleIds = $teamRoles->pluck('id');

        // Batch load role permissions
        if ($roleIds->isNotEmpty()) {
            $rolePermissions = DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->whereIn('permission_role.role', $roleIds)
                ->selectRaw(constructComplexPermissionKeySql('permission_role'). ', permission_role.role as role')
                ->get()
                ->groupBy('role');

            // Cache role permissions for this request
            foreach ($rolePermissions as $roleId => $permissions) {
                $this->requestCache["role_permissions.{$roleId}"] = collect($permissions)->pluck('complex_permission_key')->all();
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
                
            // Cache team role permissions for this request
            foreach ($teamRolePermissions as $teamRoleId => $permissions) {
                $this->requestCache["team_role_permissions.{$teamRoleId}"] = collect($permissions)->pluck('complex_permission_key')->all();
            }
        }
    }
    
    /**
     * Build the complete permission set for a user
     */
    private function buildUserPermissionSet(Collection $teamRoles, $teamIds = null)
    {
        $permissions = collect();

        foreach ($teamRoles as $teamRole) {
            // Get teams this role has access to
            $accessibleTeams = collect($this->getTeamRoleAccessibleTeams($teamRole));
            
            // Filter by target teams if specified
            if ($teamIds !== null) {
                $targetTeams = collect(is_iterable($teamIds) ? $teamIds : [$teamIds]);
                $accessibleTeams = $accessibleTeams->intersect($targetTeams);
            }
            
            if ($accessibleTeams->isEmpty()) {
                continue;
            }
            
            // Add role-based permissions
            $rolePermissions = $this->getRolePermissions($teamRole->roleRelation);
            $permissions = $permissions->concat($rolePermissions);
            
            // Add direct team role permissions
            $directPermissions = $this->getTeamRolePermissions($teamRole);
            $permissions = $permissions->concat($directPermissions);
        }
        
        return $permissions->unique()->all();
    }
    
    /**
     * Get teams accessible through a team role (considering hierarchy)
     */
    private function getTeamRoleAccessibleTeams(TeamRole $teamRole)
    {
        $cacheKey = "team_role_access.{$teamRole->id}";
        
        return $this->getRequestCache($cacheKey, function() use ($teamRole, $cacheKey) {
            return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL, function() use ($teamRole) {
                $teams = collect([$teamRole->team_id]);
                
                if ($teamRole->getRoleHierarchyAccessBelow()) {
                    $descendants = $this->hierarchyService->getDescendantTeamIds($teamRole->team_id);
                    $teams = $teams->concat($descendants);
                }
                
                if ($teamRole->getRoleHierarchyAccessNeighbors()) {
                    $siblings = $this->hierarchyService->getSiblingTeamIds($teamRole->team_id);
                    $teams = $teams->concat($siblings);
                }
                
                return $teams->unique()->all();
            });
        });
    }
    
    /**
     * Get permissions from role with efficient query
     */
    private function getRolePermissions($role)
    {
        if (!$role) {
            return [];
        }
        
        $cacheKey = "role_permissions.{$role->id}";
        
        // Check request cache first
        if (isset($this->requestCache[$cacheKey])) {
            $permissions = $this->requestCache[$cacheKey];
        } else {
            $permissions = Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL, function() use ($role) {
                return $role->permissions()->selectRaw(constructComplexPermissionKeySql('permission_role'))
                    ->get()->all();
            });
        }
        
        return $permissions;
    }
    
    /**
     * Get direct team role permissions
     */
    private function getTeamRolePermissions(TeamRole $teamRole)
    {
        $cacheKey = "team_role_permissions.{$teamRole->id}";
        
        // Check request cache first
        if (isset($this->requestCache[$cacheKey])) {
            $permissions = $this->requestCache[$cacheKey];
        } else {
            $permissions = Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL, function() use ($teamRole) {
                return $teamRole->permissions()
                    ->selectRaw(constructComplexPermissionKeySql('permission_team_role'))
                    ->get()->all();
            });
        }
        
        return $permissions;
    }

    /**
     * Get teams where user has specific permission (not global)
     */
    private function getTeamSpecificPermissions(int $userId, string $permissionKey, PermissionTypeEnum $type)
    {
        $teamsWithPermission = collect();
        $teamRoles = $this->getUserActiveTeamRoles($userId);
        
        foreach ($teamRoles as $teamRole) {
            if ($this->teamRoleHasPermission($teamRole, $permissionKey, $type)) {
                $accessibleTeams = $this->getTeamRoleAccessibleTeams($teamRole);
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
        PermissionTypeEnum $type
    ): bool {
        // Check role permissions first (most common case)
        if ($teamRole->roleRelation) {
            $rolePermissions = $this->getRolePermissions($teamRole->roleRelation);
            
            foreach ($rolePermissions as $permission) {
                if (getPermissionKey($permission) === $permissionKey && 
                    getPermissionType($permission['type']) !== PermissionTypeEnum::DENY &&
                    PermissionTypeEnum::hasPermission(getPermissionType($permission['type']), $type)) {
                    return true;
                }
            }
        }
        
        // Check direct team role permissions
        $directPermissions = $this->getTeamRolePermissions($teamRole);
        
        foreach ($directPermissions as $permission) {
            if (getPermissionKey($permission) === $permissionKey && 
                getPermissionType($permission['type']) !== PermissionTypeEnum::DENY &&
                PermissionTypeEnum::hasPermission(getPermissionType($permission['type']), $type)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check for explicit deny permissions
     */
    private function hasExplicitDeny(Collection $permissions, string $permissionKey): bool
    {
        return $permissions->contains(function($permission) use ($permissionKey) {
            return getPermissionKey($permission) === $permissionKey && 
                   getPermissionType($permission) === PermissionTypeEnum::DENY;
        });
    }
    
    /**
     * Check if user has required permission type
     */
    private function hasRequiredPermission(
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
     * Build optimized cache key
     */
    private function buildPermissionCacheKey(int $userId, $teamIds = null): string
    {
        $teamKey = $teamIds ? 
            md5(json_encode(collect($teamIds)->sort()->values())) : 
            'all';
            
        return "user_permissions.{$userId}.{$teamKey}";
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
        
        // Check super admin status (cached)
        $cacheKey = "user_super_admin.{$userId}";
        
        return $this->getRequestCache($cacheKey, function() use ($userId, $cacheKey) {
            return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL * 4, function() use ($userId) {
                $user = \App\Models\User::find($userId);
                return $user && $user->isSuperAdmin();
            });
        });
    }
    
    /**
     * Get or set request-level cache to avoid repeated computations
     */
    private function getRequestCache(string $key, callable $callback = null)
    {
        if (!isset($this->requestCache[$key])) {
            if ($callback) {
                $this->requestCache[$key] = $callback();
            } else {
                return null;
            }
        }
        
        return $this->requestCache[$key];
    }
    
    /**
     * Clear request-level cache
     */
    public function clearRequestCache(): void
    {
        $this->requestCache = [];
    }
    
    /**
     * Clear user-specific permission cache
     */
    public function clearUserCache(int $userId): void
    {
        $patterns = [
            "user_permissions.{$userId}.*",
            "user_teams_with_permission.{$userId}.*",
            "user_all_accessible_teams.{$userId}",
            "user_active_team_roles.{$userId}.*",
            "accessible_teams.{$userId}.*",
            "user_super_admin.{$userId}"
        ];
          foreach ($patterns as $pattern) {
            Cache::forgetTagsPattern([self::CACHE_TAG], $pattern);
        }
        
        // Clear request cache for this user
        $keysToRemove = array_filter(array_keys($this->requestCache), function($key) use ($userId) {
            return str_contains($key, ".{$userId}.");
        });
        
        foreach ($keysToRemove as $key) {
            unset($this->requestCache[$key]);
        }
    }
    
    /**
     * Clear all permission cache
     */
    public function clearAllCache(): void
    {
        Cache::flushTags([self::CACHE_TAG]);
        $this->clearRequestCache();
    }
    
    /**
     * Get cache statistics for monitoring
     */
    public function getCacheStats(): array
    {
        $redisMemory = 0;
        if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
            try {
                $redis = \Redis::connection();
                $info = $redis->info('memory');
                $redisMemory = $info['used_memory'] ?? 0;
            } catch (\Exception $e) {
                // Redis not available or error
            }
        }
        
        return [
            'request_cache_keys' => count($this->requestCache),
            'memory_usage' => $redisMemory,
            'cache_driver' => get_class(Cache::getStore())
        ];
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
     * Get performance metrics for debugging
     */
    public function getPerformanceMetrics(): array
    {
        return [
            'request_cache_size' => count($this->requestCache),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'cache_keys' => array_keys($this->requestCache)
        ];
    }
}