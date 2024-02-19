<?php

namespace Kompo\Auth\Models\Teams;

trait BelongsToTeamTrait
{
    /* RELATIONS */
    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /* CALCULATED FIELDS */
    public function getTeamName()
    {
        return $this->team->name;
    }

    /* ACTIONS */
    public function setTeamId($teamId = null)
    {
        $this->team_id = $teamId ?: currentTeamId();
    }

    /* SCOPES */
    public function scopeForTeam($query, $teamId = null)
    {
        return $query->where('team_id', $teamId ?: currentTeamId());
    }

    public function deletable()
    {
        return isSuperAdmin() || $this->team_id == currentTeamId();
    }

}
