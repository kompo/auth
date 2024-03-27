<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Form;

class TeamInvitationMultiForm extends Form
{
    public $model = TeamRole::class;

    public function render()
    {
        return _Columns(
            TeamRole::buttonGroupField()->col('col-6'),
            TeamRole::roleHierarchySelect()->col('col-5'),
            $this->deleteRoleChoice()->col('col-1'),
        )->alignCenter();
    }

    protected function deleteRoleChoice()
    {
        return $this->model->id ?

            _Link()->iconDelete()->selfDelete('deleteTeamRole', ['id' => $this->model->id])->emitDirect('deleted') :

            _Link()->iconDelete()->emitDirect('deleted');
    }
}