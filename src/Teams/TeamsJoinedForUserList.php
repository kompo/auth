<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Facades\UserModel;
use Kompo\Query;

class TeamsJoinedForUserList extends Query
{
    protected $userId;
    protected $user;

    public function created()
    {
        $this->userId = $this->prop('user_id');
        $this->user = UserModel::findOrFail($this->userId);
    }

    public function query()
    {
        return $this->user->teamRoles()->with('team')->latest();
    }

    public function render($teamRole)
    {
        return _FlexBetween(

            _Html($teamRole->getTeamName())->class('text-gray-600'),

            _Pill($teamRole->getRoleName()),

            _Link('Show permissions')->class('text-sm text-red-500')
                ->selfPost('displayUserPermissions', [
                    'id' => $teamRole->id,
                ])->inPanel1()

        )->class('py-4');
    }

    public function displayUserPermissions($id)
    {
        return new UserPermissionsForTeamRoleList([
            'team_role_id' => $id,
        ]);
    }
}
