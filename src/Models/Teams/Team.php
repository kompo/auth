<?php

namespace Kompo\Auth\Models\Teams;

use Condoedge\Utils\Models\Model;
use App\Models\User;

class Team extends Model
{
    use \Condoedge\Utils\Models\Tags\MorphToManyTagsTrait;
    use \Condoedge\Utils\Models\Files\MorphManyFilesTrait;
    use \Condoedge\Utils\Models\ContactInfo\Maps\MorphManyAddresses;
    use \Condoedge\Utils\Models\ContactInfo\Email\MorphManyEmails;
    use \Condoedge\Utils\Models\ContactInfo\Phone\MorphManyPhones;

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

    /**
     * Get all the children teams ids with a raw sql query (A lot faster than Eloquent)
     * @param mixed $depth If you want to limit the depth of the search
     * @param array|null  $staticExtraSelect Used to assign a key-value pair to the result
     * @param mixed $staticExtraSelect.0 The value of the extra select
     * @param mixed $staticExtraSelect.1 The key of the extra select
     * @param string $search A search string to filter the results
     * @return \Illuminate\Support\Collection
     */
    public function getAllChildrenRawSolution($depth = null, $staticExtraSelect = null, $search = '')
    {
		if (!$this->teams()->count()) { 
			return collect($staticExtraSelect ? [$this->id => $staticExtraSelect[0]] : [$this->id]);
		}

		$currentLevel = 1;
		$query = \DB::table("teams as t$currentLevel")->where("t$currentLevel.id", $this->id);

        $allIds = $search && !str_contains($this->team_name, $search) ? collect() : collect($staticExtraSelect ? [$this->id => $staticExtraSelect[0]] : [$this->id]);

		while ((!$depth || $currentLevel < $depth)  &&(clone $query)->selectRaw("COUNT(t$currentLevel.id) as count")->first()->count) {
			$lastestCurrentLevel = $currentLevel;
			$currentLevel++;
			$query->leftJoin("teams as t$currentLevel", "t$currentLevel.parent_team_id", '=', "t$lastestCurrentLevel.id");
        
            $selectRaw = "t$currentLevel.id" . ($staticExtraSelect ? ', "' . ($staticExtraSelect[0] . '" as ' . $staticExtraSelect[1]) : "");

            $pluckArgs = $staticExtraSelect ? [$staticExtraSelect[1], "id"] : ["id"];

            $levelIds = (clone $query)->selectRaw($selectRaw)
                ->when($search, fn($q) => $q->where("t$currentLevel.team_name", 'LIKE', wildcardSpace($search)))
                ->pluck(...$pluckArgs);

            $allIds = $allIds->union($levelIds);
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
            if ((clone $query)->where("t$currentLevel.id", $childrenId)->count()) {
                return true;
            }
            
			$lastestCurrentLevel = $currentLevel;
			$currentLevel++;
			$query->leftJoin("teams as t$currentLevel", "t$currentLevel.parent_team_id", '=', "t$lastestCurrentLevel.id");
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
        return $query->where('team_name', 'LIKE', wildcardSpace($search));
    }

    public function scopeActive($query)
	{
		$query->where(fn($q) => $q->whereNull('inactive_at')->orWhere('inactive_at', '>', now()));
	}

    public function scopeValidForTasks($query)
    {
        return $query;
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
