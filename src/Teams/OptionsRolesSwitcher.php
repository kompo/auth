<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Common\Form;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Models\Teams\TeamRole;

class OptionsRolesSwitcher extends Form
{
    public function render()
    {
        return _Rows(
            _Rows(
                _Select()->class('max-w-2xl min-w-[260px]')->options(config('kompo-auth.profile-enum')::optionsWithLabels())->default(currentTeamRole()?->role?->profile ?? 1)->name('profile')
                    ->selfPost('roleOptions')->withAllFormValues()->inPanel('role-switcher'),
                _Input()->placeholder('translate.search-placeholder')->name('search')
                    ->selfPost('roleOptions')->withAllFormValues()->inPanel('role-switcher'),
            )->p4()->class('!pb-0'),
            _Panel(
                $this->roleOptions()
            )->id('role-switcher')
        );
    }

    public function roleOptions() 
    {
        // We want to show all virtual teams roles that the user has access to. also using hierarchy
        $teamsIdsWithRoles = auth()->user()->getAllTeamIdsWithRolesCached(request('profile') ?? 1, request('search'))->take(10)
            ->filter(fn($roleId, $teamId) => $roleId !== currentTeamRole()?->role_id || $teamId !== currentTeamRole()?->team_id);

        // Using this to avoid querying the database for each team and role
        $teams = TeamModel::whereIn('id', $teamsIdsWithRoles->keys())->get()->mapWithKeys(function ($team) {
            return [$team->id => $team];
        });
        $roles = RoleModel::whereIn('id', $teamsIdsWithRoles->values())->get()->mapWithKeys(function ($role) {
            return [$role->id => $role];
        });

        // getAllTeamIdsWithRoles return an key-value => team_id => role_id array
        $rolesSelectors = $teamsIdsWithRoles->sort()
            ->map(fn($roleId, $teamId) =>  $this->getTeamRoleLabel($teams[$teamId], $roles[$roleId])
                    ->selfPost('switchToTeamRole', ['team_id' => $teamId, 'role_id' => $roleId])->redirect('dashboard')
        );

        return _Rows(
            !$rolesSelectors->count() ? _Html('permissions-no-roles-in-this-profile')->class('text-center text-gray-500 text-sm p-4 !pt-0') : null,
            ...$rolesSelectors,
        );
    }

    protected function getTeamRoleLabel($team, $role)
    {
        return _FlexBetween(
            _Rows(
                _Html($team->team_name)->class('text-sm font-medium'),
                _Html($role->name)->class('text-sm text-greenmain opacity-70'),
            ),

            $team->rolePill(),
        )->class('w-72 px-4 py-2 gap-4');
    }

    public function switchToTeamRole($teamId, $roleId)
    {
        if (!auth()->user()->hasAccessToTeam($teamId)) {
            abort(403);
        }

        $teamRole = TeamRole::getOrCreateForUser($teamId, auth()->id(), $roleId);

        auth()->user()->switchToTeamRoleId($teamRole->id);
    }
}