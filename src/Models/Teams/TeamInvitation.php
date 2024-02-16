<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\ModelBase;

class TeamInvitation extends ModelBase
{
    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
