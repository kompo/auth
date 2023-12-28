<?php

namespace Kompo\Auth\Teams;

class TeamMembersView extends TeamBaseForm
{
    protected $_Title = 'Team Members';
    protected $_Description = 'All of the people that are part of this team.';

    protected function body()
    {
        return [
            new TeamMembersList()
        ];
    }
}