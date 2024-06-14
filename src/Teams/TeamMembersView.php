<?php

namespace Kompo\Auth\Teams;

class TeamMembersView extends TeamBaseForm
{
    protected $_Title = 'crm.team-members';
    protected $_Description = 'crm.team-members-desc';

    protected function body()
    {
        return [
            new TeamMembersList()
        ];
    }
}
