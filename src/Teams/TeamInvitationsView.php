<?php

namespace Kompo\Auth\Teams;

class TeamInvitationsView extends TeamBaseForm
{
    protected $_Title = 'Pending Team Invitations';
    protected $_Description = 'These people have been invited to your team and have been sent an invitation email. They may join the team by accepting the email invitation.';

    protected function body()
    {
        return [
            new TeamInvitationsList()
        ];
    }
}