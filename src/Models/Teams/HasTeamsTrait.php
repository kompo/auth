<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Teams\BaseRoles\SuperAdminRole;
use Kompo\Auth\Models\Teams\PermissionTeamRole;
use Kompo\Auth\Models\Teams\TeamRole;

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
	/* RELATIONS */
    
    /**
     * Gets the user's currently selected team role.
     * If none is set, attempts to select the first available role.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currentTeamRole()
	{
        // Auto-select first team role if none is set
        if ($this->exists && !$this->current_team_role_id) {
            if(!$this->switchToFirstTeamRole()) {
                auth()->logout();
                abort(403, __('auth-you-dont-have-access-to-any-team'));
            }
        }

        // Note: The global scope is disabled for the authenticated user to prevent permission deadlocks
		return $this->belongsTo(TeamRole::class, 'current_team_role_id')
            ->when(auth()->id() == $this->id, function ($q) {
                $q->withoutGlobalScope('authUserHasPermissions');
            });
	}

    /**
     * Gets teams owned by this user.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function ownedTeams()
    {
        return $this->hasMany(config('kompo-auth.team-model-namespace'));
    }

    /**
     * Gets all teams the user belongs to (regardless of role).
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function teams()
    {
        return $this->belongsToMany(config('kompo-auth.team-model-namespace'), TeamRole::class)->withPivot('role');
    }

    /**
     * Gets all team roles of the user.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function teamRoles()
    {
    	return $this->hasMany(TeamRole::class)
            ->when(auth()->id() == $this->id, function ($q) {
                $q->withoutGlobalScope('authUserHasPermissions');
            });
    }

    /**
     * Gets active team roles (excludes inactive teams).
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function activeTeamRoles()
    {
        // withoutGlobalScope prevents infinite loop in permissions check
        return $this->teamRoles()->whereHas('team', fn($q) => $q->active()
            ->when(auth()->id() == $this->id, function ($q) {
                $q->withoutGlobalScope('authUserHasPermissions');
            })
        );
    }

    /* CALCULATED FIELDS */
    
    /**
     * Gets team roles related to a specific team (direct or hierarchical).
     * 
     * @param int|null $teamId Optional team ID to filter by
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRelatedTeamRoles($teamId = null)
    {
        return $this->teamRoles()->relatedToTeam($teamId)->get();
    }

    /**
     * Gets the first team role for a team.
     * If no role exists for the specified team, attempts to create a child role
     * from a parent team if hierarchical permissions are enabled.
     * 
     * @param int|null $teamId Optional team ID to filter by
     * @return TeamRole|null The first team role or null
     */
    public function getFirstTeamRole($teamId = null)
    {
        return $this->teamRoles()->relatedToTeam($teamId)->first() ?? 
            TeamRole::getParentHierarchyRole($teamId, $this->id)?->createChildForHierarchy($teamId);
    }

    /**
     * Gets the most recently created team role for a team.
     * 
     * @param int|null $teamId Optional team ID to filter by
     * @return TeamRole|null The latest team role or null
     */
    public function getLatestTeamRole($teamId = null)
    {
        return $this->teamRoles()->relatedToTeam($teamId)->latest()->first();        
    }

    /**
     * Checks if a team role belongs to this user.
     * 
     * @param TeamRole $teamRole The team role to check
     * @return bool True if the team role belongs to this user
     */
    public function isOwnTeamRole($teamRole)
    {
        return $this->id == $teamRole->user_id;
    }

    /**
     * Checks if the user owns a team.
     * 
     * @param Team|null $team The team to check
     * @return bool True if the user owns the team
     */
    public function ownsTeam($team)
    {
        if (is_null($team)) {
            return false;
        }

        return $this->id == $team->user_id;
    }

    /**
     * Checks if the user has access to a team.
     * Optionally filtered by a specific role.
     * Uses caching for performance.
     * 
     * @param int $teamId The team ID to check
     * @param string|null $roleId Optional role ID to filter by
     * @return bool True if the user has access
     */
    public function hasAccessToTeam($teamId, $roleId = null)
    {
        return \Cache::rememberWithTags(['permissions'], 'hasAccessToTeam' . $this->id . '|' . $teamId . '|' . ($roleId ?? ''), 120, fn() =>
            $this->activeTeamRoles()
                ->when($roleId, fn($q) => $q->where('role', $roleId))
                ->get()
                ->some(fn($tr) => $tr->hasAccessToTeam($teamId))    
        );
    }

    /**
     * Gets all team IDs where the user has a role with the specified profile.
     * Uses caching for performance when no search term is provided.
     * 
     * @param int $profile The role profile to filter by
     * @param string $search Optional search term
     * @return \Illuminate\Support\Collection Collection of team IDs
     */
    public function getAllTeamIdsWithRolesCached($profile = 1, $search = '')
    {
        if($search) {
            return $this->getAllTeamIdsWithRoles($profile, $search);
        }

        $cacheKey = 'allTeamIdsWithRoles' . $this->id . '|' . $profile;

        return \Cache::rememberWithTags(['permissions'], $cacheKey, 180, fn() => $this->getAllTeamIdsWithRoles($profile, $search));
    }

    /**
     * Gets all team IDs where the user has a role with the specified profile.
     * 
     * @param int $profile The role profile to filter by
     * @param string $search Optional search term
     * @return \Illuminate\Support\Collection Collection of team IDs
     */
    public function getAllTeamIdsWithRoles($profile = 1, $search = '')
    {
        return $this->activeTeamRoles()->whereHas('roleRelation', fn($q) => $q->where('profile', $profile))->get()
            ->mapWithKeys(fn($tr) => $tr->getAllHierarchyTeamsIds($search));
    }

	/* ACTIONS */
	
	/**
     * Creates a personal team for the user and assigns ownership role.
     * 
     * @return Team The newly created team
     */
	public function createPersonalTeamAndOwnerRole()
    {
        $team = Team::forceCreate([
            'user_id' => $this->id,
            'team_name' => explode(' ', $this->name, 2)[0]."'s Team",
        ]);

        $this->createTeamOwnerRole($team);

        return $team;
    }

    /**
     * Creates or updates a team role for the user.
     * 
     * @param Team $team The team
     * @param string $role The role identifier
     * @param string|null $hierarchy Optional hierarchy type
     * @return TeamRole The team role
     */
    public function createTeamRole($team, $role, $hierarchy = null)
    {
        // Check if the role already exists
        if ($teamRole = $this->teamRoles()->where('team_id', $team->id)->where('role', $role)->first()) {
            if($hierarchy) {
                $teamRole->role_hierarchy = $hierarchy;
                $teamRole->systemSave();
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

        return $teamRole;
    }

    /**
     * Creates team roles from an invitation.
     * 
     * @param TeamInvitation $invitation The invitation containing role information
     */
    public function createRolesFromInvitation($invitation)
    {
        $team = $invitation->team;

        $roles = explode(TeamRole::ROLES_DELIMITER, $invitation->role);
        $hierarchies = explode(TeamRole::ROLES_DELIMITER, $invitation->role_hierarchy);

        collect($roles)->each(fn($role, $key) => $this->createTeamRole($team, $role, $hierarchies[$key] ?? null));
        
        $this->switchToFirstTeamRole($invitation->team_id);

        $invitation->delete();
    }

    /**
     * Switches the user to their first available role in a team.
     * 
     * @param int|null $teamId Optional team ID
     * @return bool True if successful, false otherwise
     */
    public function switchToFirstTeamRole($teamId = null)
    {
        $teamRole = $this->getFirstTeamRole($teamId);

        if (!$teamRole) {
            return false;
        }

        return $this->switchToTeamRole($teamRole);
    }

    /**
     * Switches the user to a specific team role by ID.
     * 
     * @param int $teamRoleId The team role ID
     * @return bool True if successful, false otherwise
     */
    public function switchToTeamRoleId($teamRoleId)
    {
        return $this->switchToTeamRole(TeamRole::findOrFail($teamRoleId));
    }

    /**
     * Switches the user to a specific team role.
     * 
     * @param TeamRole $teamRole The team role
     * @return bool True if successful, false otherwise
     */
    public function switchToTeamRole($teamRole)
    {
        // Verify ownership of the team role
        if (!$this->isOwnTeamRole($teamRole)) {
            return false;
        }

        // Update current team role
        $this->forceFill([
            'current_team_role_id' => $teamRole->id,
        ])->save();

        $this->setRelation('currentTeamRole', $teamRole);

        // Update cache
        $this->refreshRolesAndPermissionsCache();

        return true;
    }

    /**
     * Switches the user to a different role within the current team.
     * 
     * @param string $role The role identifier
     * @throws \Illuminate\Auth\Access\AuthorizationException If role is not available
     */
    public function switchRole($role)
    {
        $availableRole = $this->teamRoles()->where('team_id', $this->current_team_id)->where('role', $role)->first();

        if (!$availableRole) {
            abort(403, __('This role is not available to this user!'));
        }

        $this->forceFill([
            'current_role' => $role,
        ])->save();
    }

    /**
     * Refreshes the roles and permissions cache for this user.
     * Called after team or role changes.
     */
    public function refreshRolesAndPermissionsCache()
    {
        $currentTeamRole = $this->currentTeamRole()->first();

        try {
            \Cache::put('currentTeamRole'.$this->id, $currentTeamRole, 120);
            \Cache::put('currentTeam'.$this->id, $currentTeamRole->team, 120);
            \Cache::put('currentPermissions'.$this->id, $currentTeamRole->permissions()->pluck('complex_permission_key'), 120);            
        } catch (\Throwable $e) {
            \Log::info('Failed writing roles and permissions to cache '.$e->getMessage());
        }
    }

    /* ROLES */
    
    /**
     * Checks if the user is the owner of the current team.
     * 
     * @return bool True if the user is the team owner
     */
    public function isTeamOwner()
    {
        return $this->ownsTeam($this->currentTeamRole->team);
    }

    /**
     * Checks if the user has the SuperAdmin role in the current team.
     * 
     * @return bool True if the user is a super admin
     */
    public function isSuperAdmin()
    {
        return $this->currentTeamRole->role === SuperAdminRole::ROLE_KEY;
    }

    /* PERMISSIONS */
    /**
     * Check if the user has a specific permission.
     * This is the core method for permission checking throughout the application.
     *
     * Permission Resolution Flow:
     * 1. Get all applicable permissions (from specified teams or all teams)
     * 2. Check if any permission matches the requested key and type
     * 3. Return true if match found, false otherwise
     *
     * @param string $permissionKey The permission key to check
     * @param PermissionTypeEnum $type The type of permission required (READ, WRITE, ALL)
     * @param array|int|null $teamsIds Optional team context (specific team IDs)
     * @return bool True if the user has the permission
     */
    public function hasPermission($permissionKey, PermissionTypeEnum $type = PermissionTypeEnum::ALL, $teamsIds = null)
    {
        // Get the list of current permissions based on the team ID
        $permissionsList = $teamsIds ? $this->getCurrentPermissionKeysInTeams($teamsIds) : $this->getCurrentPermissionsInAllTeams();

        // Check if the permission key exists in the list with the specified type
        return $permissionsList->first(fn($key) => $permissionKey == getPermissionKey($key) && PermissionTypeEnum::hasPermission(getPermissionType($key), $type));
    }

    /**
     * Gets the IDs of teams where the user has a specific permission.
     * Useful for filtering records by teams where the user has access.
     *
     * @param string $permissionKey The permission key to check
     * @param PermissionTypeEnum $type The type of permission required
     * @return \Illuminate\Support\Collection Collection of team IDs
     */
    public function getTeamsIdsWithPermission($permissionKey, PermissionTypeEnum $type = PermissionTypeEnum::ALL)
    {
        $cacheKey = 'teamsWithPermission' . $this->id . '|' . $permissionKey . '|' . $type->value;

        return \Cache::rememberWithTags(['permissions'], $cacheKey, 120, function () use ($permissionKey, $type) {
            // Check if any active team role denies the permission
            $hasDenyingPermission = $this->activeTeamRoles->some(function ($teamRole) use ($permissionKey) {
                return $teamRole->denyingPermission($permissionKey);
            });

            if ($hasDenyingPermission) {
                return collect([]);
            }

            // Filter roles that have the permission
            $rolesWithPermission = $this->activeTeamRoles->filter(function ($teamRole) use ($permissionKey, $type) {
                return $teamRole->hasPermission($permissionKey, $type);
            });

            // Reduce to get all teams with access (includes hierarchical teams)
            $teamsWithAccess = $rolesWithPermission->reduce(function ($carry, $teamRole) {
                return $carry->concat($teamRole->getAllTeamsWithAccess());
            }, collect([]));

            return $teamsWithAccess;
        });
    }

    /**
     * Gets all permissions across all teams for the user.
     * Used for global permission checks.
     *
     * @return \Illuminate\Support\Collection Collection of permission keys
     */
    public function getCurrentPermissionsInAllTeams()
    {
        return \Cache::rememberWithTags(['permissions'], 'currentPermissionsInAllTeams' . $this->id, 120,
            fn() => TeamRole::getAllPermissionsKeysForMultipleRoles($this->activeTeamRoles),
        );
    }

    /**
     * Gets permission keys for specific teams.
     * Used for team-specific permission checks.
     *
     * @param array|int $teamsIds Team ID(s) to check
     * @return \Illuminate\Support\Collection Collection of permission keys
     */
    public function getCurrentPermissionKeysInTeams($teamsIds)
    {
        $teamsIds = collect(is_iterable($teamsIds) ? $teamsIds : [$teamsIds]);

        return \Cache::rememberWithTags(['permissions'], 'currentPermissionKeys' . $this->id . '|' . $teamsIds->implode(','), 120,
            fn() => TeamRole::getAllPermissionsKeysForMultipleRoles($this->activeTeamRoles->filter(fn($tr) => $tr->hasAccessToTeamOfMany($teamsIds))),
        );
    }

    // ! DISABLED FOR NOW.
    private function getCurrentPermissionKeys()
    {
        return $this->currentTeamRole->getAllPermissionsKeys();
    }

    /**
     * Grants a permission to the user in their current team role.
     * Note: This is a direct permission assignment, not recommended for normal use.
     *
     * @param string $permissionKey The permission key to grant
     * @param int|null $teamRoleId Optional team role ID (defaults to current)
     * @return void
     */
    public function givePermissionTo($permissionKey, $teamRoleId = null)
    {
        $permission = Permission::findByKey($permissionKey);

        return $this->givePermissionId($permission->id, $teamRoleId);
    }

    /**
     * Grants a permission by ID to the user in their current team role.
     * Note: This is a direct permission assignment, not recommended for normal use.
     *
     * @param int $permissionId The permission ID to grant
     * @param int|null $teamRoleId Optional team role ID (defaults to current)
     * @return void
     */
    public function givePermissionId($permissionId, $teamRoleId = null)
    {
        $teamRoleId = $teamRoleId ?: $this->current_team_role_id;

        $permissionTeamRole = PermissionTeamRole::forPermission($permissionId)->forTeamRole($teamRoleId)->first();

        if (!$permissionTeamRole) {
            $permissionTeamRole = new PermissionTeamRole();
            $permissionTeamRole->team_role_id = $teamRoleId;
            $permissionTeamRole->permission_id = $permissionId;
            $permissionTeamRole->save();
        }

        $this->refreshRolesAndPermissionsCache();
    }
}
