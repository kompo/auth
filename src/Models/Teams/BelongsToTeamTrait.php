<?php

namespace Kompo\Auth\Models\Teams;

trait BelongsToTeamTrait
{
    /* RELATIONS */
    public function team()
    {
        return $this->belongsTo(config('kompo-auth.team-model-namespace'));
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
        scopeWhereBelongsTo($query, 'team_id', $teamIdOrIds, currentTeamId());
    }

    public function deletable()
    {
        return isSuperAdmin() || $this->team_id == currentTeamId();
    }

}
