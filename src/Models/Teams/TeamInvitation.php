<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\ModelBase;

class TeamInvitation extends ModelBase
{
    /* RELATIONSHIPS */
    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /* CALCULATED FIELDS */
    public function getAcceptInvitationRoute()
    {
        return \URL::signedRoute('team-invitations.accept', [
            'id' => $this->id,
        ]);
    }

    public function getRegisterFromInvitationRoute()
    {
        return \URL::signedRoute('team-invitations.register', [
            'invitation' => $this,
        ]);
    }
}
