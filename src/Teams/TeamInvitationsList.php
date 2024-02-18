<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Models\Teams\TeamInvitation;
use Kompo\Query;

class TeamInvitationsList extends Query
{
    protected $team;

    public $id = 'team-invitations-list';

    public function created()
    {
        $this->team = auth()->user()->currentTeam;
    }

    public function query()
    {
        return $this->team->teamInvitations()->latest();
    }

    public function render($teamInvitation)
    {
        return _FlexBetween(

            _Html($teamInvitation->email)->class('text-gray-600'),

            _Link('Cancel')->class('text-sm text-red-500')
                ->selfPost('cancelInvitation', [
                    'id' => $teamInvitation->id,
                ])->alert('Invitation cancelled!')->browse()

        )->class('py-4');
    }

    public function cancelInvitation($id)
    {
        TeamInvitation::findOrFail($id)->delete();
    }
}
