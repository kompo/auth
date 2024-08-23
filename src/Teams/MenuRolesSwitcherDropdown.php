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
                [
                    _Rows(
                        _Select()->class('max-w-2xl min-w-[260px]')->options(config('kompo-auth.profile-enum')::optionsWithLabels())->default(currentTeamRole()?->role?->profile ?? 1)->name('profile')
                            ->selfGet('roleOptions')->inPanel('role-switcher'),
                    )->p4()->class('!pb-0'),
                    _Panel(
                        $this->roleOptions()
                    )->id('role-switcher'),
                ]
            )->alignRight()->class('scrollableDropdown');
    }

    public function roleOptions($profile = 1) 
    {
        $roles = auth()->user()->teamRoles()->where('id', '<>', currentTeamRoleId())
            ->whereHas('roleRelation', fn($q) => $q->where('profile', $profile))->with('team')->get()
            ->sortBy(auth()->user()->getRolesSortBy())
            ->unique(
                fn($tr) => $tr->team_id . $tr->role_id
            )->mapWithKeys(fn($teamRole) => [
                $teamRole->id => $this->getTeamRoleLabel($teamRole, $teamRole->team->rolePill())->selfPost('switchToTeamRole', ['id' => $teamRole->id])->redirect('dashboard')
            ]);
        
        return _Rows(
            !$roles->count() ? _Html('permissions-no-roles-in-this-profile')->class('text-center text-gray-500 text-sm p-4 !pt-0') : null,
            ...$roles,
        );
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
