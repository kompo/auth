<?php

namespace Kompo\Auth\Models\Teams;

use Condoedge\Utils\Models\ModelBase;

class TeamInvitation extends ModelBase
{
    use \Kompo\Auth\Models\Teams\BelongsToTeamTrait;
    
    /* RELATIONSHIPS */

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
