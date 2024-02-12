<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Teams\BaseRoles\SuperAdminRole;
use Kompo\Auth\Models\Teams\BaseRoles\TeamOwnerRole;
use Kompo\Auth\Models\Teams\TeamRole;

trait HasTeamsTrait
{
	/* RELATIONS */
    public function currentTeam()
	{
		return $this->belongsTo(Team::class, 'current_team_id');
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

    public function switchTeam($team)
    {
        if (!$this->isMemberOfTeam($team)) {
            return false;
        }

        $rolesArray = $team->teamRoles()->where('user_id', $this->id)->pluck('role');

        $this->forceFill([
            'current_team_id' => $team->id,
            'current_role' => $rolesArray->first(),
        ])->save();

        $this->setAvailableRoles();

        $this->setRelation('currentTeam', $team);

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

    public function setAvailableRoles()
    {
        $rolesArray = $this->teamRoles()->where('team_id', $this->current_team_id)->pluck('role');

        $this->forceFill([
            'available_roles' => $rolesArray->implode(TeamRole::ROLES_DELIMITER),
        ])->save();
    }

    public function isMemberOfTeam($team)
    {
    	return $this->teamRoles()->where('team_id', $team->id)->count();
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
        return $this->ownsTeam($this->currentTeam);
    }

    public function isSuperAdmin()
    {
        return $this->hasCurrentRole(SuperAdminRole::ROLE_KEY);
    }

    public function hasCurrentRole($role)
    {
        return $this->current_role === $role;
    }
}
