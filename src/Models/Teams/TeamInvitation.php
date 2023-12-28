<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Model;

class TeamInvitation extends Model
{
    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
