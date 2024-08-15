<?php

namespace Kompo\Auth\Models\Notes;

use Kompo\Auth\Models\Model;

class Note extends Model
{
    use \Kompo\Auth\Models\Teams\BelongsToTeamTrait;

    protected $casts = [
        'date_nt' => 'datetime',
    ];

    /* RELATIONSHIPS */
    public function notable()
    {
        return $this->morphTo();
    }

    /* SCOPES */
    public function scopeForNotable($query, $notable)
    {
        return $query->where('notable_type', get_class($notable))->where('notable_id', $notable->id);
    }

    public function scopeForNotableType($query, $notableType)
    {
        return $query->where('notable_type', $notableType);
    }

    public function scopeForNotableId($query, $notableId)
    {
        return $query->where('notable_id', $notableId);
    }

	public function scopeForTeam($query, $team = null)
    {
        return $query->where('team_id', $team?->id ?? currentTeamId());
    }

    /* ACTIONS */
    public function save(array $options = [])
    {
        $this->team_id = currentTeamId();

        return parent::save($options);
    }

    public function deletable()
    {
        return $this->added_by == auth()->id() || auth()->user()->isSuperAdmin();
    }
}