<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\Modal;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Models\Teams\ProfileEnum;

class RoleForm extends Modal
{
    protected $_Title = 'translate.add-role';
    protected $noHeaderButtons = true;

    public $class = 'min-w-96';

    public $model = RoleModel::class;

    public function beforeSave()
    {
        $this->model->id = $this->model->id ?? \Str::snake(request('name'));
    }

    public function afterSave()
    {
        $this->model->assignTeamLevels(request('team_levels'));
    }

    public function body()
    {
        return _Rows(
            _Input('translate.role-name')->name('name')->required(),
            _Textarea('translate.role-description')->name('description'),
            _Image('translate.role-icon')->name('icon'),

            _Rows($this->extraFields()),

            _Select('translate.profile')->name('profile')->options(
                ProfileEnum::optionsWithLabels(),
            )->overModal('profile'),

            _Rows(
                _Toggle('translate.accept-roll-to-child')->name('accept_roll_to_child'),
                _Toggle('translate.accept-roll-to-neighbourg')->name('accept_roll_to_neighbourg'),
            ),

            _Flex(
                $this->model->id ? _DeleteButton('generic.delete')->outlined()->byKey($this->model)->class('w-full') : null,
                _SubmitButton('generic.save')->closeModal()->refresh('roles-manager')->class('w-full'),
            )->class('gap-4')
            
            // _Input('Role Permissions')->name('role_permissions')->required(),
        );
    }

    protected function extraFields()
    {
        return null;
    }
}