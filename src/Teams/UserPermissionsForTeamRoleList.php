<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Query;

class UserPermissionsForTeamRoleList extends Query
{
    protected $teamRoleId;
    protected $teamRole;

    public function created()
    {
        $this->teamRoleId = $this->prop('team_role_id');
        $this->teamRole = TeamRole::findOrFail($this->teamRoleId);
    }

    public function query()
    {
        return $this->teamRole->permissions()->latest();
    }

    public function render($permission)
    {
        return _FlexBetween(

            _Html($permission->permission_name)->class('text-gray-600'),

        )->class('py-4');
    }
}
