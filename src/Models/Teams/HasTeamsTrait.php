<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Teams\BaseRoles\SuperAdminRole;
use Kompo\Auth\Models\Teams\PermissionTeamRole;
use Kompo\Auth\Models\Teams\TeamRole;

trait HasTeamsTrait
{
	/* RELATIONS */
    public function currentTeamRole()
	{
        if (!$this->current_team_role_id) {
            $this->switchToFirstTeamRole();
        }

		return $this->belongsTo(TeamRole::class, 'current_team_role_id');
	}

    public function ownedTeams()
    {
        return $this->hasMany(config('kompo-auth.team-model-namespace'));
    }

    public function teams()
    {
        return $this->belongsToMany(config('kompo-auth.team-model-namespace'), TeamRole::class)->withPivot('role');
    }

    public function teamRoles()
    {
    	return $this->hasMany(TeamRole::class);
    }

    public function activeTeamRoles()
    {
        return $this->teamRoles()->whereHas('team', fn($q) => $q->active());
    }

    /* SCOPES */
    

    /* CALCULATED FIELDS */
    public function getRelatedTeamRoles($teamId = null)
    {
        return $this->teamRoles()->relatedToTeam($teamId)->get();
    }

    public function getFirstTeamRole($teamId = null)
    {
        return $this->teamRoles()->relatedToTeam($teamId)->first();        
    }

    public function getLatestTeamRole($teamId = null)
    {
        return $this->teamRoles()->relatedToTeam($teamId)->latest()->first();        
    }

    public function isOwnTeamRole($teamRole)
    {
        return $this->id == $teamRole->user_id;
    }

    public function ownsTeam($team)
    {
        if (is_null($team)) {
            return false;
        }

        return $this->id == $team->user_id;
    }

	/* ACTIONS */
	public function createPersonalTeamAndOwnerRole()
    {
        $team = Team::forceCreate([
            'user_id' => $this->id,
            'team_name' => explode(' ', $this->name, 2)[0]."'s Team",
        ]);

        $this->createTeamOwnerRole($team);

        return $team;
    }

    public function createTeamRole($team, $role, $hierarchy = null)
    {
        $teamRole = new TeamRole();
        $teamRole->team_id = $team->id;
        $teamRole->user_id = $this->id;
        $teamRole->role = $role;
        $teamRole->role_hierarchy = $hierarchy ?: RoleHierarchyEnum::DIRECT;
        $teamRole->save();

        return $teamRole;
    }

    public function createRolesFromInvitation($invitation)
    {
        $team = $invitation->team;

        $roles = explode(TeamRole::ROLES_DELIMITER, $invitation->role);
        $hierarchies = explode(TeamRole::ROLES_DELIMITER, $invitation->role_hierarchy);

        collect($roles)->each(fn($role, $key) => $this->createTeamRole($team, $role, $hierarchies[$key] ?? null));
        
        $this->switchToFirstTeamRole($invitation->team_id);

        $invitation->delete();
    }

    public function switchToFirstTeamRole($teamId = null)
    {
        $teamRole = $this->getFirstTeamRole($teamId);

        return $this->switchToTeamRole($teamRole);
    }

    public function switchToTeamRoleId($teamRoleId)
    {
        return $this->switchToTeamRole(TeamRole::findOrFail($teamRoleId));
    }

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

        return true;
    }

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
    public function isTeamOwner()
    {
        return $this->ownsTeam($this->currentTeamRole->team);
    }

    public function isSuperAdmin()
    {
        return $this->currentTeamRole->role === SuperAdminRole::ROLE_KEY;
    }

    /* PERMISSIONS */
    /**
     * Check if the user has a specific permission.
     *
     * @param string $permissionKey The permission key to check.
     * @param PermissionTypeEnum $type The type of the permission (default is PermissionTypeEnum::ALL).
     * @param int|null $teamId The ID of the team to check the permission in (optional).
     * @return bool True if the user has the permission, false otherwise.
     */
    public function hasPermission($permissionKey, PermissionTypeEnum $type = PermissionTypeEnum::ALL, $teamId = null)
    {
        // Get the list of current permissions based on the team ID
        $permissionsList = $teamId ? $this->getCurrentPermissionKeysInTeam($teamId) : $this->getCurrentPermissionsInAllTeams();

        // Check if the permission key exists in the list with the specified type
        return $permissionsList->first(fn($key) => $permissionKey == getPermissionKey($key) && PermissionTypeEnum::hasPermission(getPermissionType($key), $type));
    }

    /**
     * Get the IDs of teams where the user has a specific permission.
     *
     * @param string $permissionKey The permission key to check.
     * @param PermissionTypeEnum $type The type of the permission (default is PermissionTypeEnum::ALL).
     * @return Collection A collection of team IDs where the user has the permission.
     */
    public function getTeamsIdsWithPermission($permissionKey, PermissionTypeEnum $type = PermissionTypeEnum::ALL)
    {
        $cacheKey = 'teamsWithPermission' . $this->id . '|' . $permissionKey . '|' . $type->value;

        return \Cache::remember($cacheKey, 120, function () use ($permissionKey, $type) {
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

            // Reduce to get all teams with access
            $teamsWithAccess = $rolesWithPermission->reduce(function ($carry, $teamRole) {
                return $carry->concat($teamRole->getAllTeamsWithAccess());
            }, collect([]));

            return $teamsWithAccess;
        });
    }

    /**
     * Get the current permissions in all teams for the user.
     *
     * @return Collection A collection of current permission keys in all teams.
     */
    public function getCurrentPermissionsInAllTeams()
    {
        return \Cache::remember('currentPermissionsInAllTeams' . $this->id, 120,
            fn() => TeamRole::getAllPermissionsKeysForMultipleRoles($this->activeTeamRoles),
        );
    }

    /**
     * Get the current permission keys in a specific team for the user.
     *
     * @param int $teamId The ID of the team.
     * @return Collection A collection of current permission keys in the specified team.
     */
    public function getCurrentPermissionKeysInTeam($teamId)
    {
        return \Cache::remember('currentPermissionKeys' . $this->id . '|' . $teamId, 120,
            fn() => TeamRole::getAllPermissionsKeysForMultipleRoles($this->activeTeamRoles->filter(fn($tr) => $tr->hasAccessToTeam($teamId))),
        );
    }

    // ! DISABLED FOR NOW.
    private function getCurrentPermissionKeys()
    {
        return $this->currentTeamRole->getAllPermissionsKeys();
    }

    public function givePermissionTo($permissionKey, $teamRoleId = null)
    {
        $permission = Permission::findByKey($permissionKey);

        return $this->givePermissionId($permission->id, $teamRoleId);
    }

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
