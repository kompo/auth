<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Teams\BaseRoles\SuperAdminRole;
use Kompo\Auth\Models\Teams\BaseRoles\TeamOwnerRole;
use Kompo\Auth\Models\Teams\TeamRole;

trait HasTeamsTrait
{
	/* RELATIONS */
    public function currentTeamRole()
	{
		return $this->belongsTo(TeamRole::class, 'current_team_role_id');
	}

    public function ownedTeams()
    {
        return $this->hasMany(Team::class);
    }

    public function teams()
    {
        return $this->belongsToMany(Team::class, TeamRole::class)->withPivot('role');
    }

    public function teamRoles()
    {
    	return $this->hasMany(TeamRole::class);
    }

    /* SCOPES */
    public function scopeWhereHasTeam($query, $teamId = null)
    {
        return $query->whereHas('teams', fn($q) => $q->where('team_id', $teamId ?: currentTeamId()));
    }

    /* CALCULATED FIELDS */
    public function collectAvailableRoles()
    {
        if (!$this->available_roles) {
            return collect();
        }

        $availableRoles = explode(TeamRole::ROLES_DELIMITER, $this->available_roles);

        return collect($availableRoles);
    }

    public function getRelatedTeamRoles($teamId = null)
    {
        return $this->teamRoles()->when($teamId, fn($q) => $q->where('team_id', $teamId))->get();
    }

    public function getFirstTeamRole($teamId = null)
    {
        return $this->teamRoles()->when($teamId, fn($q) => $q->where('team_id', $teamId))->first();        
    }

	/* ACTIONS */
	public function createPersonalTeamAndOwnerRole()
    {
        $team = Team::forceCreate([
            'user_id' => $this->id,
            'name' => explode(' ', $this->name, 2)[0]."'s Team",
        ]);

        $this->createTeamRole($team, TeamOwnerRole::ROLE_KEY);

        return $team;
    }

    public function createTeamRole($team, $role)
    {
        $teamRole = new TeamRole();
        $teamRole->team_id = $team->id;
        $teamRole->user_id = $this->id;
        $teamRole->role = $role;
        $teamRole->save();
    }

    public function createSuperAdminRole($team)
    {
        $this->createTeamRole($team, SuperAdminRole::ROLE_KEY);
    }

    public function createTeamOwnerRole($team)
    {
        $this->createTeamRole($team, TeamOwnerRole::ROLE_KEY);
    }

    public function createRolesFromInvitation($invitation)
    {
        $team = $invitation->team;

        $roles = explode(TeamRole::ROLES_DELIMITER, $invitation->role);

        collect($roles)->each(fn($role) => $this->createTeamRole($team, $role));
        
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

        refreshCurrentTeamAndRole();

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

    /* ROLES */
    public function isTeamOwner()
    {
        return $this->ownsTeam($this->currentTeamRole->team);
    }

    public function isSuperAdmin()
    {
        return $this->hasCurrentRole(SuperAdminRole::ROLE_KEY);
    }

    public function hasCurrentRole($role)
    {
        return $this->currentTeamRole->role === $role;
    }
}
