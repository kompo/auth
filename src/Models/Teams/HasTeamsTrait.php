<?php

namespace Kompo\Auth\Models\Teams;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Teams\PermissionResolver;
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
     * Cache for frequently accessed data during request lifecycle
     */
    private array $requestCache = [];
    
    /**
     * Get or set cached data for current request
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
     * Optimized team access checking
     */
    public function hasAccessToTeam(int $teamId, string $roleId = null): bool
    {
        $cacheKey = "team_access_{$teamId}_" . ($roleId ?? 'any');        return $this->getRequestCache($cacheKey, function() use ($teamId, $roleId) {
            return Cache::rememberWithTags(
                ['permissions-v2'],
                "user_team_access.{$this->id}.{$teamId}." . ($roleId ?? 'any'),
                900,
                function() use ($teamId, $roleId) {
                    $teamRoles = $this->getActiveTeamRolesOptimized($roleId);
                    
                    return $teamRoles->some(function($teamRole) use ($teamId) {
                        return $teamRole->hasAccessToTeam($teamId);
                    });
                }
            );
        });
    }
    
    /**
     * Get active team roles with optimized loading
     */
    private function getActiveTeamRolesOptimized(string $roleId = null): Collection
    {
        $cacheKey = "active_team_roles_" . ($roleId ?? 'all');
        
        return $this->getRequestCache($cacheKey, function() use ($roleId) {
            return $this->activeTeamRoles()
                ->with(['team:id,team_name,parent_team_id', 'roleRelation:id,name,profile'])
                ->when($roleId, fn($q) => $q->where('role', $roleId))
                ->get();
        });
    }
    
    /**
     * Get all teams with specific permission (optimized)
     */
    public function getTeamsIdsWithPermission(
        string $permissionKey, 
        PermissionTypeEnum $type = PermissionTypeEnum::ALL
    ): Collection {
        $cacheKey = "teams_with_permission_{$permissionKey}_{$type->value}";
          return $this->getRequestCache($cacheKey, function() use ($permissionKey, $type) {
            return Cache::rememberWithTags(
                ['permissions-v2'],
                "user_teams_with_permission.{$this->id}.{$permissionKey}.{$type->value}",
                900,
                function() use ($permissionKey, $type) {
                    // Use the permission resolver for efficient checking
                    $resolver = app(PermissionResolver::class);
                    
                    // First check if user has global permission
                    if ($resolver->userHasPermission($this->id, $permissionKey, $type)) {
                        return $this->getAllAccessibleTeamIds();
                    }
                    
                    // Otherwise check team-specific permissions
                    return $this->getTeamSpecificPermissions($permissionKey, $type);
                }
            );
        });
    }
    
    /**
     * Get all accessible team IDs for this user
     */
    public function getAllAccessibleTeamIds(): Collection
    {        return $this->getRequestCache('all_accessible_teams', function() {
            return Cache::rememberWithTags(
                ['permissions-v2'],
                "user_all_accessible_teams.{$this->id}",
                900,
                function() {
                    $accessibleTeams = collect();
                    $teamRoles = $this->getActiveTeamRolesOptimized();
                    
                    // Use batch processing for efficiency
                    OptimizedTeamRole::batchLoadPermissions($teamRoles);
                    
                    foreach ($teamRoles as $teamRole) {
                        $teams = $teamRole->getAccessibleTeamsOptimized();
                        $accessibleTeams = $accessibleTeams->concat($teams);
                    }
                    
                    return $accessibleTeams->unique()->values();
                }
            );
        });
    }
    
    /**
     * Get teams where user has specific permission (not global)
     */
    private function getTeamSpecificPermissions(string $permissionKey, PermissionTypeEnum $type): Collection
    {
        $teamsWithPermission = collect();
        $teamRoles = $this->getActiveTeamRolesOptimized();
        
        foreach ($teamRoles as $teamRole) {
            if ($this->teamRoleHasPermission($teamRole, $permissionKey, $type)) {
                $accessibleTeams = $teamRole->getAccessibleTeamsOptimized();
                $teamsWithPermission = $teamsWithPermission->concat($accessibleTeams);
            }
        }
        
        return $teamsWithPermission->unique()->values();
    }
    
    /**
     * Check if team role has specific permission (optimized)
     */
    private function teamRoleHasPermission(
        TeamRole $teamRole, 
        string $permissionKey, 
        PermissionTypeEnum $type
    ): bool {
        $cacheKey = "team_role_permission_{$teamRole->id}_{$permissionKey}_{$type->value}";
        
        return $this->getRequestCache($cacheKey, function() use ($teamRole, $permissionKey, $type) {
            // Check role permissions first (most common case)
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
        });
    }
    
    /**
     * Get all team IDs with roles (optimized for role switcher)
     */
    public function getAllTeamIdsWithRolesCached($profile = 1, $search = ''): Collection
    {
        // Don't cache searches to avoid memory bloat
        if ($search) {
            return $this->getAllTeamIdsWithRoles($profile, $search);
        }

        $cacheKey = "all_teams_with_roles_{$profile}";
          return $this->getRequestCache($cacheKey, function() use ($profile) {
            return Cache::rememberWithTags(
                ['permissions-v2'],
                "allTeamIdsWithRoles.{$this->id}.{$profile}",
                180,
                fn() => $this->getAllTeamIdsWithRoles($profile, '')
            );
        });
    }    /**
     * Get team IDs with roles (base implementation)
     */
    public function getAllTeamIdsWithRoles($profile = 1, $search = ''): Collection
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

        return $result;
    }
    
    /**
     * Create team and owner role (optimized)
     */
    public function createPersonalTeamAndOwnerRole()
    {
        DB::transaction(function() {
            $team = config('kompo-auth.team-model-namespace')::forceCreate([
                'user_id' => $this->id,
                'team_name' => explode(' ', $this->name, 2)[0] . "'s Team",
            ]);

            $this->createTeamOwnerRole($team);
            
            // Clear relevant caches
            $this->clearPermissionCache();
            
            return $team;
        });
    }
    
    /**
     * Create team role with cache invalidation
     */
    public function createTeamRole($team, $role, $hierarchy = null)
    {
        $teamRole = TeamRole::where('team_id', $team->id)
            ->where('user_id', $this->id)
            ->where('role', $role)
            ->first();
            
        if ($teamRole) {
            if ($hierarchy && $teamRole->role_hierarchy !== $hierarchy) {
                $teamRole->role_hierarchy = $hierarchy;
                $teamRole->systemSave();
                $this->clearPermissionCache();
            }
            return $teamRole;
        }

        // Create new role
        $teamRole = new TeamRole();
        $teamRole->team_id = $team->id;
        $teamRole->user_id = $this->id;
        $teamRole->role = $role;
        $teamRole->role_hierarchy = $hierarchy ?: RoleHierarchyEnum::DIRECT;
        $teamRole->systemSave();
        
        // Clear caches
        $this->clearPermissionCache();
        $this->clearRequestCache();

        return $teamRole;
    }
    
    /**
     * Switch to team role with cache management
     */
    public function switchToTeamRole($teamRole)
    {
        if (!$this->isOwnTeamRole($teamRole)) {
            return false;
        }

        $this->forceFill([
            'current_team_role_id' => $teamRole->id,
        ])->save();

        $this->setRelation('currentTeamRole', $teamRole);
        $this->refreshRolesAndPermissionsCache();
        $this->clearRequestCache();

        return true;
    }
    
    /**
     * Refresh roles and permissions cache (optimized)
     */
    public function refreshRolesAndPermissionsCache(): void
    {
        // Clear old cache first
        $this->clearPermissionCache();
        
        try {
            $currentTeamRole = $this->currentTeamRole()->first();
            
            if ($currentTeamRole) {
                // Pre-warm critical caches                Cache::put('currentTeamRole' . $this->id, $currentTeamRole, 900);
                Cache::put('currentTeam' . $this->id, $currentTeamRole->team, 900);
                  // Pre-load permissions asynchronously if possible
                if (config('queue.default') !== 'sync') {
                    dispatch(function() {
                        app(PermissionCacheManager::class)->warmUserCache($this->id);
                    })->afterResponse();
                } else {
                    // Synchronous pre-loading
                    app(PermissionCacheManager::class)->warmUserCache($this->id);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to refresh roles and permissions cache: ' . $e->getMessage(), [
                'user_id' => $this->id,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Clear this user's permission cache
     */
    public function clearPermissionCache(): void
    {
        if (app()->bound(PermissionCacheManager::class)) {
            app(PermissionCacheManager::class)->invalidateByChange('team_role_changed', [
                'user_ids' => [$this->id]
            ]);
        }
        
        // Clear request cache as well
        $this->clearRequestCache();
    }
    
    /**
     * Memory-efficient role checking
     */
    public function isTeamOwner(): bool
    {
        return $this->getRequestCache('is_team_owner', function() {
            return $this->ownsTeam(currentTeam());
        });
    }
    
    /**
     * Super admin check with caching
     */
    public function isSuperAdmin(): bool
    {
        return $this->getRequestCache('is_super_admin', function() {
            return in_array($this->email, config('kompo-auth.superadmin-emails', []));
        });
    }
    
    /**
     * Get first team role with optimization
     */
    public function getFirstTeamRole($teamId = null)
    {
        $cacheKey = "first_team_role_" . ($teamId ?? 'any');
        
        return $this->getRequestCache($cacheKey, function() use ($teamId) {
            return $this->teamRoles()
                ->relatedToTeam($teamId)
                ->with(['team', 'roleRelation'])
                ->first() ?? 
                TeamRole::getParentHierarchyRole($teamId, $this->id)?->createChildForHierarchy($teamId);
        });
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
            ->get()
            ->groupBy('user_id');
            
        // Pre-warm permission cache for all users
        $cacheManager = app(PermissionCacheManager::class);
        foreach ($userIds as $userId) {
            try {
                $cacheManager->warmUserCache($userId);
            } catch (\Throwable $e) {
                Log::warning("Failed to pre-warm permissions for user {$userId}: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Get memory usage for debugging
     */
    public function getMemoryUsage(): array
    {
        return [
            'request_cache_size' => count($this->requestCache),
            'request_cache_keys' => array_keys($this->requestCache),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }
}
