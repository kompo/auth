<?php

namespace Kompo\Auth\Teams;

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

        return _Dropdown(currentTeamRole()->getRoleName())
            ->submenu(
                auth()->user()->teamRoles()->where('id', '<>', currentTeamRoleId())->with('team')->get()->unique(
                    fn($tr) => $tr->team_id . $tr->role_id
                )->mapWithKeys(fn($teamRole) => [
                    $teamRole->id => $this->getTeamRoleLabel($teamRole)->selfPost('switchToTeamRole', ['id' => $teamRole->id])->redirect('dashboard')
                ])
            )->alignRight();
    }

    protected function getTeamRoleLabel($teamRole)
    {
        return _Rows(
            _Html($teamRole->getRoleName()),
            _Html($teamRole->getTeamName())->class('text-sm text-gray-400'),
        )->class('w-72 px-4 py-2');
    }

    public function switchToTeamRole($id)
    {
        auth()->user()->switchToTeamRoleId($id);
    }
}