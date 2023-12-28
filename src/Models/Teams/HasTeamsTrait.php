<?php

namespace Kompo\Auth\Models\Teams;

trait HasTeamsTrait
{
	/* RELATIONS */
    public function currentTeam()
	{
		if (is_null($this->current_team_id) && $this->id) {

			if (!$this->teamRoles()->count()) {
				$this->createPersonalTeam();
			}

            $this->switchTeam($this->teamRoles()->first()->team);
        }

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

	/* ACTIONS */
	protected function createPersonalTeam()
    {
        $team = Team::forceCreate([
            'user_id' => $this->id,
            'name' => explode(' ', $this->name, 2)[0]."'s Team",
        ]);

        TeamRole::createTeamRole(TeamRole::ROLE_OWNER, $team, $this);
    }

    public function switchTeam($team)
    {
        if (!$this->belongsToTeam($team)) {
            return false;
        }

        $this->forceFill([
            'current_team_id' => $team->id,
            'current_role' => $team->teamRoles()->where('user_id', $this->id)->value('role'),
        ])->save();

        $this->setRelation('currentTeam', $team);

        return true;
    }

    public function belongsToTeam($team)
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
        return $this->hasCurrentRole(TeamRole::ROLE_SUPERADMIN);
    }

    public function hasCurrentRole($role)
    {
        return $this->current_role === $role;
    }
}
