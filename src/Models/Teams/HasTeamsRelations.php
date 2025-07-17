<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Teams\TeamRole;

/**
 * Handles only Eloquent relationships
 */
trait HasTeamsRelations
{    
    /* RELATIONS */
    public function currentTeamRole()
    {
        // Auto-select first team role if none is set
        if ($this->exists && !$this->current_team_role_id) {
            if(!$this->switchToFirstTeamRole()) {
                if (auth()->isImpersonating()) {
                    auth()->leaveImpersonation();
                } else {
                    auth()->logout();
                }
                
                abort(403, __('auth-you-dont-have-access-to-any-team'));
            }
        }

        return $this->belongsTo(TeamRole::class, 'current_team_role_id')
            ->when(auth()->id() == $this->id, function ($q) {
                $q->withoutGlobalScope('authUserHasPermissions');
            });
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
        return $this->hasMany(TeamRole::class)
            ->when(auth()->id() == $this->id, function ($q) {
                $q->withoutGlobalScope('authUserHasPermissions');
            });
    }

    public function activeTeamRoles()
    {
        return $this->teamRoles()->whereHas('team', fn($q) => $q->active()
            ->when(auth()->id() == $this->id, function ($q) {
                $q->withoutGlobalScope('authUserHasPermissions');
            })
        );
    }    /* CALCULATED FIELDS - Basic getters only */
    public function getRelatedTeamRoles($teamId = null)
    {
        return $this->teamRoles()->relatedToTeam($teamId)->get();
    }

    public function getFirstTeamRole($teamId = null)
    {
        return $this->teamRoles()->relatedToTeam($teamId)->has('team')->has('roleRelation')->first() ?? 
            TeamRole::getParentHierarchyRole($teamId, $this->id)?->createChildForHierarchy($teamId);
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
}
