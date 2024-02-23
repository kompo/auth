<?php

namespace Kompo\Auth\Teams;

use Kompo\Form;

class TeamManagementPage extends Form
{
    public function render()
    {
    	return _Rows(

            new TeamInfoForm(),

            new TeamAddressForm(),

            new TeamsJoinedView(),

            auth()->user()->can('addTeamMember', currentTeam()) ?
                new TeamInvitationForm() : 
                null,

            auth()->user()->can('addTeamMember', currentTeam()) ?
                new TeamInvitationsView() : 
                null, 

            new TeamMembersView(),
            
    	)->class('space-y-4');
    }
}