<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Models\Teams\PermissionTeamRole;
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
        $userPermissions = $this->getUserPermissionsOptimized($userId, $teamIds);
        
        // Check for explicit DENY first (highest priority)
        if ($this->hasExplicitDeny($userPermissions, $permissionKey)) {
            return false;
        }
        
        // Check for required permission
        return $this->hasRequiredPermission($userPermissions, $permissionKey, $type);
    }
    
    /**
     * Optimized user permissions retrieval with smart caching
     */    private function getUserPermissionsOptimized(int $userId, $teamIds = null): Collection
    {
        $cacheKey = $this->buildPermissionCacheKey($userId, $teamIds);
        
        return Cache::rememberWithTags(
            [self::CACHE_TAG],
            $cacheKey, 
            self::CACHE_TTL, 
            fn() => $this->resolveUserPermissions($userId, $teamIds)
        );
    }
    
    /**
     * Core permission resolution logic
     */
    private function resolveUserPermissions(int $userId, $teamIds = null): Collection
    {
        // Get user's active team roles with optimized loading
        $teamRoles = $this->getUserActiveTeamRoles($userId, $teamIds);
        
        if ($teamRoles->isEmpty()) {
            return collect();
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
        $query = TeamRole::with(['roleRelation', 'team'])
            ->where('user_id', $userId)
            ->whereNull('terminated_at')
            ->whereNull('suspended_at')
            ->whereHas('team', fn($q) => $q->whereNull('deleted_at'));
            
        // Apply team filtering if specified
        if ($teamIds !== null) {
            $targetTeamIds = collect(is_iterable($teamIds) ? $teamIds : [$teamIds]);
            
            // Get all teams that could grant access to target teams
            $accessibleTeamIds = $this->getAccessibleTeamIds($userId, $targetTeamIds);
            
            $query->whereIn('team_id', $accessibleTeamIds);
        }
        
        return $query->get();
    }
    
    /**
     * Get all team IDs that could grant access to target teams through hierarchy
     */
    private function getAccessibleTeamIds(int $userId, Collection $targetTeamIds): Collection
    {
        $cacheKey = "accessible_teams.{$userId}." . $targetTeamIds->sort()->implode(',');
        
        return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL, function() use ($userId, $targetTeamIds) {
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
            Role::with(['permissions'])->whereIn('id', $roleIds)->get();
        }
        
        // Batch load team role permissions
        if ($teamRoleIds->isNotEmpty()) {
            PermissionTeamRole::with(['permission'])
                ->whereIn('team_role_id', $teamRoleIds)
                ->get()
                ->groupBy('team_role_id');
        }
    }
    
    /**
     * Build the complete permission set for a user
     */
    private function buildUserPermissionSet(Collection $teamRoles, $teamIds = null): Collection
    {
        $permissions = collect();
        
        foreach ($teamRoles as $teamRole) {
            // Get teams this role has access to
            $accessibleTeams = $this->getTeamRoleAccessibleTeams($teamRole);
            
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
        
        return $permissions->unique();
    }
    
    /**
     * Get teams accessible through a team role (considering hierarchy)
     */    private function getTeamRoleAccessibleTeams(TeamRole $teamRole): Collection
    {
        $cacheKey = "team_role_access.{$teamRole->id}";
        
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
            
            return $teams->unique();
        });
    }
    
    /**
     * Get permissions from role with efficient query
     */
    private function getRolePermissions($role): Collection
    {
        if (!$role) {
            return collect();
        }
        
        $cacheKey = "role_permissions.{$role->id}";
        
        return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL, function() use ($role) {
            return $role->permissions->map(function($permission) {
                return [
                    'key' => $permission->permission_key,
                    'type' => PermissionTypeEnum::from($permission->pivot->permission_type),
                    'source' => 'role'
                ];
            });
        });
    }
    
    /**
     * Get direct team role permissions
     */
    private function getTeamRolePermissions(TeamRole $teamRole): Collection
    {
        $cacheKey = "team_role_permissions.{$teamRole->id}";
        
        return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL, function() use ($teamRole) {
            return $teamRole->permissions->map(function($permission) {
                return [
                    'key' => $permission->permission_key,
                    'type' => PermissionTypeEnum::from($permission->pivot->permission_type),
                    'source' => 'team_role'
                ];
            });
        });
    }
    
    /**
     * Check for explicit deny permissions
     */
    private function hasExplicitDeny(Collection $permissions, string $permissionKey): bool
    {
        return $permissions
            ->where('key', $permissionKey)
            ->where('type', PermissionTypeEnum::DENY)
            ->isNotEmpty();
    }
    
    /**
     * Check if user has required permission type
     */
    private function hasRequiredPermission(
        Collection $permissions, 
        string $permissionKey, 
        PermissionTypeEnum $requiredType
    ): bool {
        $userPermissions = $permissions->where('key', $permissionKey);
        
        foreach ($userPermissions as $permission) {
            if (PermissionTypeEnum::hasPermission($permission['type'], $requiredType)) {
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
        
        return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL * 4, function() use ($userId) {
            $user = \App\Models\User::find($userId);
            return $user && $user->isSuperAdmin();
        });
    }
    
    /**
     * Clear user-specific permission cache
     */
    public function clearUserCache(int $userId): void
    {
        $patterns = [
            "user_permissions.{$userId}.*",
            "accessible_teams.{$userId}.*",
            "user_super_admin.{$userId}"
        ];
          foreach ($patterns as $pattern) {
            Cache::forgetTagsPattern([self::CACHE_TAG], $pattern);
        }
    }
    
    /**
     * Clear all permission cache
     */
    public function clearAllCache(): void
    {
        Cache::flushTags([self::CACHE_TAG]);
    }
    
    /**
     * Get cache statistics for monitoring
     */
    public function getCacheStats(): array
    {
        // Implementation would depend on your cache driver
        return [
            'total_keys' => 0, // Implement based on cache driver
            'memory_usage' => 0,
            'hit_rate' => 0
        ];
    }
}
