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

    protected $deleteSecurityRestrictions = true;
    protected $saveSecurityRestrictions = true;

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

    public function getAllChildrenRawSolution()
    {
		if (!$this->teams()->count()) { 
			return collect([$this->id]);
		}

		$currentLevel = 1;
		$query = \DB::table("teams as t$currentLevel")->where("t$currentLevel.id", $this->id);

        $allIds = collect([$this->id]);

		while ((clone $query)->selectRaw("COUNT(t$currentLevel.id) as count")->first()->count) {
			$lastestCurrentLevel = $currentLevel;
			$currentLevel++;
			$query->leftJoin("teams as t$currentLevel", "t$currentLevel.parent_team_id", '=', "t$lastestCurrentLevel.id");
        
            $levelIds = (clone $query)->select("t$currentLevel.id")->pluck("id");
            $allIds = $allIds->merge($levelIds);
        }

		return $allIds;
    }

    public function hasChildrenIdRawSolution($childrenId)
    {
        if ($this->id == $childrenId) {
            return true;
        }

		if (!$this->teams()->count()) { 
			return false;
		}

		$currentLevel = 1;
		$query = \DB::table("teams as t$currentLevel")->where("t$currentLevel.id", $this->id);

		while ((clone $query)->selectRaw("COUNT(t$currentLevel.id) as count")->first()->count) {
			$lastestCurrentLevel = $currentLevel;
			$currentLevel++;
			$query->leftJoin("teams as t$currentLevel", "t$currentLevel.parent_team_id", '=', "t$lastestCurrentLevel.id");
        
            if ((clone $query)->where("t$currentLevel.id", $childrenId)->count()) {
                return true;
            }
        }

		return false;
    }

    public function rolePill()
    {
        return null;
    }

    public function getFullInfoTableElement()
    {
        return _Rows(
            _Html($this->team_name)->class('font-semibold'),
            _Html($this->getParentTeams()->pluck('team_name')->implode('<br>'))->class('text-sm text-gray-500'),
        );
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

    public function scopeSearch($query, $search)
    {
        $query->where('team_name', 'LIKE', wildcardSpace($search));
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
