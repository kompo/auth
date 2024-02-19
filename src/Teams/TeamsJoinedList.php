<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Query;

class TeamsJoinedList extends Query
{
    public function query()
    {
        return TeamRole::where('user_id', auth()->id())->with('team')->latest();
    }

    public function render($teamRole)
    {
        $roleClass = $teamRole->getRelatedRoleClass();

        return _FlexBetween(

            _Html($teamRole->getTeamName())->class('text-gray-600'),

            _Pill($roleClass::ROLE_NAME),

            _Link('Switch to')->class('text-sm text-red-500')
                ->selfPost('switchToTeamRole', [
                    'id' => $teamRole->id,
                ])->redirect()

        )->class('py-4');
    }

    public function switchToTeamRole($id)
    {
        $teamRole = TeamRole::findOrFail($id);

        auth()->user()->switchTeam($teamRole->team);

        return redirect()->route('teams.manage');
    }
}
