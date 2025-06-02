<?php

namespace Kompo\Auth\Models\Teams;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Optimized TeamRole model with efficient queries and caching
 */
class OptimizedTeamRole extends TeamRole
{
    /**
     * Scope for active team roles with optimized loading
     */
    public function scopeActiveOptimized(Builder $query): Builder
    {
        return $query->select([
                'team_roles.id',
                'team_roles.user_id', 
                'team_roles.team_id',
                'team_roles.role',
                'team_roles.role_hierarchy',
                'team_roles.created_at'
            ])
            ->whereNull('team_roles.terminated_at')
            ->whereNull('team_roles.suspended_at')
            ->whereNull('team_roles.deleted_at')
            ->with([
                'team:id,team_name,parent_team_id,deleted_at,inactive_at',
                'roleRelation:id,name,profile'
            ]);
    }
    
    /**
     * Get accessible teams with optimized hierarchy resolution
     */
    public function getAccessibleTeamsOptimized(): Collection
    {
        $cacheKey = "team_role_accessible.{$this->id}";
          return Cache::rememberWithTags(['permissions-v2'], $cacheKey, 900, function() {
            $teams = collect([$this->team_id]);
            $hierarchyService = app(\Kompo\Auth\Teams\TeamHierarchyService::class);
            
            // Use batch operations for hierarchy
            if ($this->getRoleHierarchyAccessBelow()) {
                $descendants = $hierarchyService->getDescendantTeamIds($this->team_id);
                $teams = $teams->concat($descendants);
            }
            
            if ($this->getRoleHierarchyAccessNeighbors()) {
                $siblings = $hierarchyService->getSiblingTeamIds($this->team_id);
                $teams = $teams->concat($siblings);
            }
            
            return $teams->unique()->values();
        });
    }
    
    /**
     * Batch load permissions for multiple team roles
     */
    public static function batchLoadPermissions(Collection $teamRoles): void
    {
        $teamRoleIds = $teamRoles->pluck('id');
        $roleIds = $teamRoles->pluck('role')->unique();
        
        // Preload role permissions
        if ($roleIds->isNotEmpty()) {
            DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->whereIn('permission_role.role', $roleIds)
                ->select([
                    'permission_role.role',
                    'permissions.permission_key',
                    'permission_role.permission_type'
                ])
                ->get()
                ->groupBy('role');
        }
        
        // Preload team role permissions
        if ($teamRoleIds->isNotEmpty()) {
            DB::table('permission_team_role')
                ->join('permissions', 'permissions.id', '=', 'permission_team_role.permission_id')
                ->whereIn('permission_team_role.team_role_id', $teamRoleIds)
                ->select([
                    'permission_team_role.team_role_id',
                    'permissions.permission_key',
                    'permission_team_role.permission_type'
                ])
                ->get()
                ->groupBy('team_role_id');
        }
    }
}