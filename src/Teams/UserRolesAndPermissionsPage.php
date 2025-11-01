<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Facades\UserModel;
use Kompo\Form;

class UserRolesAndPermissionsPage extends Form
{
    public $model = UserModel::class;

    public function authorizeBoot()
    {
        if (!$this->model->id) {
            $this->model(auth()->user());
        }

        return auth()->user()->can('managePermissions', $this->model);
    }

    public function created()
    {
        
    }

    public function render()
    {
    	return _Rows(

            new TeamsJoinedForUserList([
                'user_id' => $this->model->id,
            ]),

            _Panel1(
                new UserPermissionsForTeamRoleList([
                    'team_role_id' => $this->model->getLatestTeamRole()->first()->id,
                ]),
            ),
            
    	)->class('space-y-4');
    }
}