<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Model;
use App\Models\User;

class Team extends Model
{
    use \Kompo\Auth\Models\Maps\MorphManyAddresses;
    use \Kompo\Auth\Models\Tags\HasManyTagsTrait;

	/* RELATIONS */
	public function owner()
    {
		return $this->belongsTo(User::class, 'user_id');
	}

    public function parentTeam()
    {
        return $this->belongsTo(Team::class, 'parent_team_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, TeamRole::class)->withPivot('role')->withTimestamps();
    }

    public function teamRoles()
    {
    	return $this->hasMany(TeamRole::class);
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
