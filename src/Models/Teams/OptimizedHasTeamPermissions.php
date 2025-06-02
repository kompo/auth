<?php

namespace Kompo\Auth\Models\Teams;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Teams\PermissionResolver;

/**
 * Optimized trait for team permissions using the new resolver
 */
trait OptimizedHasTeamPermissions
{
    private static ?PermissionResolver $permissionResolver = null;
    
    /**
     * Get the permission resolver instance
     */
    private function getPermissionResolver(): PermissionResolver
    {
        if (self::$permissionResolver === null) {
            self::$permissionResolver = app(PermissionResolver::class);
        }
        
        return self::$permissionResolver;
    }
    
    /**
     * Optimized permission checking
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
     * Check access to specific team
     */    public function hasAccessToTeam(int $teamId, string $roleId = null): bool
    {
        $cacheKey = "user_team_access.{$this->id}.{$teamId}." . ($roleId ?? 'any');
        
        return Cache::rememberWithTags(['permissions-v2'], $cacheKey, 900, function() use ($teamId, $roleId) {
            $teamRoles = $this->activeTeamRoles()
                ->when($roleId, fn($q) => $q->where('role', $roleId))
                ->get();
                
            return $teamRoles->some(function($teamRole) use ($teamId) {
                return $teamRole->hasAccessToTeam($teamId);
            });
        });
    }
    
    /**
     * Get teams where user has specific permission
     */
    public function getTeamsIdsWithPermission(
        string $permissionKey, 
        PermissionTypeEnum $type = PermissionTypeEnum::ALL
    ): Collection {
        $cacheKey = "user_teams_with_permission.{$this->id}.{$permissionKey}.{$type->value}";
          return Cache::rememberWithTags(['permissions-v2'], $cacheKey, 900, function() use ($permissionKey, $type) {
            // Check for global permission first
            if ($this->hasPermission($permissionKey, $type)) {
                // If user has global access, return all accessible teams
                return $this->getAllAccessibleTeamIds();
            }
            
            // Otherwise, check team-specific permissions
            $teamsWithPermission = collect();
            $teamRoles = $this->activeTeamRoles()->with(['roleRelation', 'permissions'])->get();
            
            foreach ($teamRoles as $teamRole) {
                if ($this->teamRoleHasPermission($teamRole, $permissionKey, $type)) {
                    $accessibleTeams = $teamRole->getAccessibleTeamsOptimized();
                    $teamsWithPermission = $teamsWithPermission->concat($accessibleTeams);
                }
            }
            
            return $teamsWithPermission->unique();
        });
    }
    
    /**
     * Get all teams the user has any access to
     */
    private function getAllAccessibleTeamIds(): Collection
    {
        $cacheKey = "user_all_accessible_teams.{$this->id}";
          return Cache::rememberWithTags(['permissions-v2'], $cacheKey, 900, function() {
            $accessibleTeams = collect();
            $teamRoles = $this->activeTeamRoles()->get();
            
            foreach ($teamRoles as $teamRole) {
                $teams = $teamRole->getAccessibleTeamsOptimized();
                $accessibleTeams = $accessibleTeams->concat($teams);
            }
            
            return $accessibleTeams->unique();
        });
    }
    
    /**
     * Check if a team role has specific permission
     */
    private function teamRoleHasPermission(
        TeamRole $teamRole, 
        string $permissionKey, 
        PermissionTypeEnum $type
    ): bool {
        // Check role permissions
        if ($teamRole->roleRelation) {
            $rolePermissions = $teamRole->roleRelation->permissions()
                ->where('permission_key', $permissionKey)
                ->wherePivot('permission_type', '!=', PermissionTypeEnum::DENY)
                ->get();
                
            foreach ($rolePermissions as $permission) {
                $permissionType = PermissionTypeEnum::from($permission->pivot->permission_type);
                if (PermissionTypeEnum::hasPermission($permissionType, $type)) {
                    return true;
                }
            }
        }
        
        // Check direct team role permissions
        $directPermissions = $teamRole->permissions()
            ->where('permission_key', $permissionKey)
            ->wherePivot('permission_type', '!=', PermissionTypeEnum::DENY)
            ->get();
            
        foreach ($directPermissions as $permission) {
            $permissionType = PermissionTypeEnum::from($permission->pivot->permission_type);
            if (PermissionTypeEnum::hasPermission($permissionType, $type)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Clear this user's permission cache
     */
    public function clearPermissionCache(): void
    {
        $this->getPermissionResolver()->clearUserCache($this->id);
    }
}