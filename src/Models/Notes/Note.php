<?php

namespace Kompo\Auth\Models\Notes;

use Kompo\Auth\Models\Model;
use Kompo\Auth\Models\Teams\Team;

class Note extends Model
{
    protected $casts = [
        'date_nt' => 'datetime',
    ];

    /* RELATIONSHIPS */
    public function notable()
    {
        return $this->morphTo();
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
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

    public function scopeForTeam($query, $team)
    {
        return $query->where('team_id', $team->id);
    }
}