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
        return $this->team->team_name;
    }

    /* ACTIONS */
    public function setTeamId($teamId = null)
    {
        $this->team_id = $teamId ?: currentTeamId();
    }

    /* SCOPES */
    public function scopeForTeam($query, $teamIdOrIds = null)
    {
        if (isWhereCondition($teamIdOrIds)) {
            $query->where('team_id', $teamIdOrIds ?: currentTeamId());
        } else {
            $query->whereIn('team_id', $teamIdOrIds);
        } 
    }

    public function deletable()
    {
        return isSuperAdmin() || $this->team_id == currentTeamId();
    }

}
