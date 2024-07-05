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
                auth()->user()->teamRoles()->where('id', '<>', currentTeamRoleId())->with('team')->get()
                ->sortBy(auth()->user()->getRolesSortBy())
                ->unique(
                    fn($tr) => $tr->team_id . $tr->role_id
                )->mapWithKeys(fn($teamRole) => [
                    $teamRole->id => $this->getTeamRoleLabel($teamRole, $teamRole->team->rolePill())->selfPost('switchToTeamRole', ['id' => $teamRole->id])->redirect('dashboard')
                ])
            )->alignRight()->class('scrollableDropdown');
    }

    protected function getTeamRoleLabel($teamRole, $pill = null)
    {
        return _FlexBetween(
            _Rows(
                _Html($teamRole->getTeamName())->class('text-sm font-medium'),
                _Html($teamRole->getRoleName())->class('text-sm text-greenmain opacity-70'),
            ),

            $pill,
        )->class('w-72 px-4 py-2 gap-4');
    }

    public function switchToTeamRole($id)
    {
        auth()->user()->switchToTeamRoleId($id);
    }
}
