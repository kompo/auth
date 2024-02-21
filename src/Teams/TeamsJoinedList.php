<?php

namespace Kompo\Auth\Teams;

use Kompo\Query;

class TeamsJoinedList extends Query
{
    public function query()
    {
        return auth()->user()->teamRoles()->with('team')->latest();
    }

    public function render($teamRole)
    {
        return _FlexBetween(

            _Html($teamRole->getTeamName())->class('text-gray-600'),

            _Pill($teamRole->getRoleName()),

            _Link('Switch to')->class('text-sm text-red-500')
                ->selfPost('switchToTeamRole', [
                    'id' => $teamRole->id,
                ])->redirect()

        )->class('py-4');
    }

    public function switchToTeamRole($id)
    {
        auth()->user()->switchToTeamRoleId($id);
        
        return redirect()->route('teams.manage');
    }
}
