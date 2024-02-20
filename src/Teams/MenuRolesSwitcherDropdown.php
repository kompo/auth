<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Form;

class MenuRolesSwitcherDropdown extends Form
{
    public function created()
    {

    }

    public function render()
    {
        if (!auth()->user()) {
            return;
        }
        
        return _Select()->name('current_team_role')->options(
            auth()->user()->teamRoles()->with('team')->get()->mapWithKeys(fn($teamRole) => [
                $teamRole->id => _Rows(
                    _Html($teamRole->getRoleName()),
                    _Html($teamRole->getTeamName())->class('text-sm text-gray-400'),
                )->selfPost('switchToTeamRole', ['id' => $teamRole->id])->redirect()
            ])
        )->value(currentTeamRoleId());
    }

    public function switchToTeamRole($id)
    {
        auth()->user()->switchToTeamRoleId($id);
        
        return redirect()->route('dashboard');
    }
}