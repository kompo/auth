<?php

namespace Kompo\Auth\Models\Teams;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Teams\PermissionCacheManager;

/**
 * HasTeamsTrait
 * 
 * Provides team-based authorization functionality for User models.
 * 
 * This trait manages:
 * - Team membership and user roles within teams
 * - Current team/role selection for the user
 * - Permission checking across teams
 * - Team hierarchy traversal
 *
 * Flow of Team-Based Authorization:
 * 1. User belongs to multiple teams (via TeamRole records)
 * 2. User sets current team role to establish context
 * 3. Permissions are checked against current team or specified team
 * 4. Team hierarchies are considered in permission resolution
 * 5. Permission results are cached for performance
 */
trait HasTeamsTrait
{
    use HasTeamsRelations;
    use HasTeamNavigation;
    use HasTeamActions;
    use HasTeamPermissions;

    /**
     * Boot method for team-related functionality
     */
    public static function bootHasTeamsTrait(): void
    {
        // Clear cache when user is updated (teams/roles might have changed)
        static::updated(function ($user) {
            $user->clearPermissionCache();
        });
        
        // Clear cache when user is deleted
        static::deleted(function ($user) {
            $user->clearPermissionCache();
        });
    }

    
    /**
     * Get memory usage for debugging
     */
    public function getTeamMemoryUsage(): array
    {
        $permissionMemory = method_exists($this, 'getPermissionMemoryUsage') ? 
            $this->getPermissionMemoryUsage() : [];
            
        return array_merge($permissionMemory, [
            'total_memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'user_id' => $this->id
        ]);
    }
    
    /**
     * Health check for user's team setup
     */
    public function validateTeamSetup(): array
    {
        $issues = [];
        
        // Check if user has teams
        if (!$this->teams()->count()) {
            $issues[] = 'User has no associated teams';
        }
        
        // Check if current_team_role_id is valid
        if (!$this->current_team_role_id) {
            $issues[] = 'User has no current_team_role_id set';
        } elseif (!$this->teamRoles()->where('id', $this->current_team_role_id)->exists()) {
            $issues[] = 'User current_team_role_id points to invalid team role';
        }
        
        // Check for orphaned team roles
        $orphanedRoles = $this->teamRoles()
            ->whereDoesntHave('team')
            ->count();
            
        if ($orphanedRoles > 0) {
            $issues[] = "User has {$orphanedRoles} team roles without valid teams";
        }
        
        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'teams_count' => $this->teams()->count(),
            'team_roles_count' => $this->teamRoles()->count(),
            'current_team_role_id' => $this->current_team_role_id
        ];
    }
    
    /**
     * Cleanup method to fix common team setup issues
     */
    public function cleanupTeamSetup(): array
    {
        $fixed = [];
        
        DB::transaction(function() use (&$fixed) {
            // Remove orphaned team roles
            $orphanedCount = $this->teamRoles()
                ->whereDoesntHave('team')
                ->delete();
                
            if ($orphanedCount > 0) {
                $fixed[] = "Removed {$orphanedCount} orphaned team roles";
            }
            
            // Fix current_team_role_id if invalid
            if ($this->current_team_role_id && 
                !$this->teamRoles()->where('id', $this->current_team_role_id)->exists()) {
                
                $firstValidRole = $this->teamRoles()->first();
                if ($firstValidRole) {
                    $this->current_team_role_id = $firstValidRole->id;
                    $this->save();
                    $fixed[] = 'Fixed current_team_role_id to point to valid role';
                } else {
                    $this->current_team_role_id = null;
                    $this->save();
                    $fixed[] = 'Cleared invalid current_team_role_id';
                }
            }
            
            // Create personal team if user has no teams
            if (!$this->teams()->count()) {
                $this->createPersonalTeamAndOwnerRole();
                $fixed[] = 'Created personal team and owner role';
            }
        });
        
        if (!empty($fixed)) {
            $this->clearPermissionCache();
        }
        
        return $fixed;
    }
    
    /**
     * Debug method to get comprehensive team information
     */
    public function getTeamDebugInfo(): array
    {
        return [
            'user_id' => $this->id,
            'current_team_role_id' => $this->current_team_role_id,
            'teams' => $this->teams()->select('teams.id', 'team_name', 'teams.user_id')->get()->toArray(),
            'team_roles' => $this->teamRoles()
                ->with(['team:id,team_name', 'roleRelation:id,name'])
                ->get()
                ->map(function($tr) {
                    return [
                        'id' => $tr->id,
                        'team_id' => $tr->team_id,
                        'team_name' => $tr->team?->team_name,
                        'role' => $tr->role,
                        'role_name' => $tr->roleRelation?->name,
                        'role_hierarchy' => $tr->role_hierarchy?->value,
                        'terminated_at' => $tr->terminated_at,
                        'suspended_at' => $tr->suspended_at
                    ];
                })
                ->toArray(),
            'accessible_teams_count' => collect($this->getAllAccessibleTeamIds())->count(),
            'validation' => $this->validateTeamSetup(),
            'memory_usage' => $this->getTeamMemoryUsage()
        ];
    }
}
