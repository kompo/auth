<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\Modal;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Models\Teams\TeamRole;

class RoleForm extends Modal
{
    use RoleElementsUtils;
    
    protected $_Title = 'permissions-add-role';
    protected $noHeaderButtons = true;

    public $class = 'min-w-96';

    public $model = RoleModel::class;

    public function beforeSave()
    {
        $this->model->id = $this->model->id ?? \Str::snake(request('name'));

        // JUST ONE ROLE
        if (request('just_one_person_per_team') && TeamRole::thereAreMoreRoleAssignsThan($this->model->id, 1)->exists()) {
            doubleExecutionCheck('advised_role_in_form_' . $this->model->id)
                ->onFirstExecutionCheck(function () {
                    throwValidationError('just_one_person_per_team', 'translate.assigned-to-many-save-again-if-you-want-to-remove-all-the-assignations');
                })->onCheckedExecutionCheck(function () {
                    $this->model->deleteAssignationsHaveMoreThan(1);
                })->execute();
        }
    }

    public function afterSave()
    {
        \Cache::forget('roles');

        // \Cache::tags(['permissions'])->flush();
        \Cache::flush();
    }

    public function response()
    {
        return $this->roleHeader($this->model);
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

                _Toggle('translate.just-one-person-per-team')->name('just_one_person_per_team'),
            ),

            _Flex(
                $this->model->id ? _DeleteButton('permissions-delete')->outlined()->byKey($this->model)->class('w-full') : null,
                _SubmitButton('permissions-save')->class('w-full')->inPanel('role-header-' . $this->model?->id)->closeModal()
                    ->onSuccess(fn($e) =>$e->selfGet('roleMultiSelect')->inPanel('multi-select-roles')),
            )->class('gap-4')
            
            // _Input('Role Permissions')->name('role_permissions')->required(),
        );
    }

    public function getRoleHeader()
    {
        return $this->roleHeader($this->model);
    }

    public function roleMultiSelect()
    {
        return $this->multiSelect(session('latest-roles') ?: []);
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