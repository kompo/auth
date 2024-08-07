<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Teams\BaseRoles\SuperAdminRole;
use Kompo\Auth\Models\Teams\BaseRoles\TeamOwnerRole;
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

    public function createSuperAdminRole($team)
    {
        $this->createTeamRole($team, SuperAdminRole::ROLE_KEY);
        $this->switchToFirstTeamRole();
    }

    public function createTeamOwnerRole($team)
    {
        $this->createTeamRole($team, TeamOwnerRole::ROLE_KEY);
        $this->switchToFirstTeamRole();
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
            \Cache::put('currentPermissions'.$this->id, $currentTeamRole->permissions()->pluck('permission_key'), 120);            
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
        return $this->hasCurrentRole(SuperAdminRole::class);
    }

    public function hasCurrentRole($roleClass)
    {
        if ($this->currentTeamRole->role === $roleClass::ROLE_KEY) {
            if (config('kompo-auth.team_hierarchy_roles')) {
                return $this->currentTeamRole->getRoleHierarchyAccessDirect();
            }

            return true;
        }

        if (config('kompo-auth.team_hierarchy_roles')) {
            $currentTeam = $this->currentTeamRole->team;
            $parentTeam = $currentTeam->parentTeam;

            while ($parentTeam) {
                $teamRole = $parentTeam->teamRoles()->forUser($this->id)->where('role', $roleClass::ROLE_KEY)->first();

                if ($teamRole) {
                    return $teamRole->getRoleHierarchyAccessBelow();
                }

                $parentTeam = $parentTeam->parentTeam;
            }
        }

        return false;
    }

    /* PERMISSIONS */

    protected function getPermissionType($permissionKey)
    {
        return PermissionTypeEnum::from((int) substr($permissionKey, 0, 1));
    }

    protected function getPermissionKey($permissionKey)
    {
        return substr($permissionKey, 2);
    }

    public function hasPermission($permissionKey, PermissionTypeEnum $type = PermissionTypeEnum::ALL)
    {
        return $this->getCurrentPermissionKeys()->first(fn($key) => $permissionKey == $this->getPermissionKey($key) && PermissionTypeEnum::hasPermission($this->getPermissionType($key)->value, $type->value));
    }

    public function getCurrentPermissionKeys()
    {
        return \Cache::remember('currentPermissionKeys'.$this->id, 120,
            fn() => $this->currentTeamRole->getAllPermissionsKeys()
        );
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
