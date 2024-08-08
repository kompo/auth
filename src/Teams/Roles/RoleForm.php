<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\Modal;
use App\Models\Teams\TeamLevelEnum;
use Kompo\Auth\Models\Teams\ProfileEnum;
use Kompo\Auth\Models\Teams\Roles\Role;

class RoleForm extends Modal
{
    protected $_Title = 'translate.add-role';
    protected $noHeaderButtons = true;

    public $class = 'min-w-96';

    public $model = Role::class;

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
            _MultiSelect('translate.role-team-levels')->name('team_levels', false)->options(
                TeamLevelEnum::optionsWithLabels(),
            )->default($this->model->allowedTeamLevels->pluck('team_level')->toArray())
            ->overModal('team-levels'),

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
}