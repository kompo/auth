<?php

namespace Kompo\Auth\Teams;

use Kompo\Form;

class TeamManagementPage extends Form
{
    public function render()
    {
    	return _Rows(

            new TeamInfoForm(),

            new TeamsJoinedView(),

            auth()->user()->can('addTeamMember', auth()->user()->currentTeam) ?
                new TeamInvitationForm() : 
                null,

            auth()->user()->can('addTeamMember', auth()->user()->currentTeam) ?
                new TeamInvitationsView() : 
                null, 

            new TeamMembersView(),
            
    	)->class('space-y-4');
    }
}