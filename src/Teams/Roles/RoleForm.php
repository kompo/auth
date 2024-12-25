<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\Modal;
use Kompo\Auth\Facades\RoleModel;

class RoleForm extends Modal
{
    protected $_Title = 'permissions-add-role';
    protected $noHeaderButtons = true;

    public $class = 'min-w-96';

    public $model = RoleModel::class;

    public function beforeSave()
    {
        $this->model->id = $this->model->id ?? \Str::snake(request('name'));
    }

    public function afterSave()
    {
        \Cache::forget('roles');

        // \Cache::tags(['permissions'])->flush();
        \Cache::flush();
    }

    public function body()
    {
        return _Rows(
            _Input('permissions-role-name')->name('name')->required(),
            _Textarea('permissions-role-description')->name('description'),
            _Image('permissions-role-icon')->name('icon'),

            _Rows($this->extraFields()),

            _Select('permissions-profile')->name('profile')->options(
                $this->profileOptions(),
            )->overModal('profile'),

            _Rows(
                _Toggle('permissions-accept-roll-down')->name('accept_roll_to_child'),
                _Toggle('permissions-accept-roll-to-neighbours')->name('accept_roll_to_neighbourg'),
            ),

            _Flex(
                $this->model->id ? _DeleteButton('permissions-delete')->outlined()->byKey($this->model)->class('w-full') : null,
                _SubmitButton('permissions-save')->class('w-full')->closeModal()
                    ->onSuccess(fn($q) =>$q->selfGet('roleMultiSelect')->inPanel('multi-select-roles')),
            )->class('gap-4')
            
            // _Input('Role Permissions')->name('role_permissions')->required(),
        );
    }

    public function roleMultiSelect()
    {
        return RolesAndPermissionMatrix::multiSelect(session('latest-roles') ?: []);
    }

    protected function profileOptions()
    {
        return config('kompo-auth.profile-enum')::optionsWithLabels();
    }

    protected function extraFields()
    {
        return null;
    }
}