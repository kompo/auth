<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Model;
use App\Models\User;

class Team extends Model
{
    use \Kompo\Auth\Models\Tags\HasManyTagsTrait;
    use \Kompo\Auth\Models\Files\MorphManyFilesTrait;
    use \Kompo\Auth\Models\Maps\MorphManyAddresses;
    use \Kompo\Auth\Models\Email\MorphManyEmails;
    use \Kompo\Auth\Models\Phone\MorphManyPhones;

	/* RELATIONS */
	public function owner()
    {
		return $this->belongsTo(User::class, 'user_id');
	}

    public function parentTeam()
    {
        return $this->belongsTo(config('kompo-auth.team-model-namespace'), 'parent_team_id');
    }

    public function teams()
    {
        return $this->hasMany(config('kompo-auth.team-model-namespace'), 'parent_team_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, TeamRole::class)->withPivot('role')->withTimestamps();
    }

    public function teamRoles()
    {
    	return $this->hasMany(TeamRole::class);
    }

    public function authUserTeamRoles()
    {
        return $this->teamRoles()->forAuthUser();
    }

    public function teamInvitations()
    {
        return $this->hasMany(TeamInvitation::class);
    }

	/* CALCULATED FIELDS */
	public function hasUserWithEmail(string $email): int
    {
        return $this->users()->where('email', $email)->count();
    }

    public static function getMainParentTeam($team)
    {
        if (!$team->parentTeam) {
            return $team;
        }

        return static::getMainParentTeam($team->parentTeam);
    }

    public function getAllParents()
    {
        if ($this->parent_team_id) {
            $parentTeam = $this->parentTeam;

            return $parentTeam->getAllParents()->prepend($parentTeam);
        }

        return collect();
    }

    public function rolePill()
    {
        return null;
    }

    /* SCOPES */
    public function scopeForParentTeam($query, $teamIdOrIds)
    {
        if (isWhereCondition($teamIdOrIds)) {
            $query->where('parent_team_id', $teamIdOrIds);
        } else {
            $query->whereIn('parent_team_id', $teamIdOrIds);
        }
    }

	/* ACTIONS */
	public function detachFromTeam($user)
	{
        //TODO: refactor for current_team_role_id
		if ($user->current_team_id === $this->id) {
            $user->forceFill([
                'current_team_id' => null,
            ])->save();
        }

        $this->users()->detach($this->user);

        if (!$this->user->teams()->count()) {
            // code...
        }
	}

	/* ELEMENTS */
}
