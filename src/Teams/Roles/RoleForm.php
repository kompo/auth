<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Modal;
use Kompo\Auth\Facades\RoleModel;

class RoleForm extends Modal
{
    use RoleElementsUtils;
    use RoleRequestsUtils;

    protected $_Title = 'permissions-add-role';
    protected $noHeaderButtons = true;

    public $class = 'min-w-96 max-w-xl';

    public $model = RoleModel::class;

    public function beforeSave()
    {
        if (!$this->model->id) {
            $this->model->id = \Str::snake(request('name')) . '-' . \Str::random(3);
        }

        if (request('just_one_per_team')) {
            $this->model->max_assignments_per_team = 1;
        } else {
            $this->model->max_assignments_per_team = null;
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
        $latestRoles = collect(session()->get('latest-roles') ?: []);
        request()->merge(['roles' => $latestRoles->push($this->model->id)->all()]);

        return $this->getRoleUpdate();
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

            _Toggle('permissions-just-one-per-team')
                ->name('just_one_per_team', false)
                ->default($this->model->max_assignments_per_team),

            _Flex(
                $this->model->id ? _DeleteButton('permissions-delete')->outlined()->byKey($this->model)->class('w-full') : null,
                _SubmitButton('permissions-save')->class('w-full')->onSuccess(
                    fn($e) => $e->inPanel('hidden-roles')->closeModal()
                        ->run('() => {
                            setTimeout(() => {
                                precreateRoleVisuals(); 
                                injectRoleContent();
                            }, 500);
                        }')
                )->onSuccess(fn($e) => $e->selfGet('roleMultiSelect')->inPanel('multi-select-roles')),
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

    public function rules()
    {
        return [
            'name' => 'required|string|max:60|unique:roles,name,' . $this->model->id,
            'description' => 'required|string|max:255',
            'icon' => 'nullable',
            'profile' => 'required|string|in:' . implode(',', array_keys($this->profileOptions()->all())),
            'accept_roll_to_child' => 'required|boolean',
            'accept_roll_to_neighbourg' => 'required|boolean',
        ];
    }
}
