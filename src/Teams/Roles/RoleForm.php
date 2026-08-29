<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Modal;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Rules\MaxTranslatable;
use Kompo\Auth\Teams\Cache\PermissionCacheInvalidator;

class RoleForm extends Modal
{
    protected $_Title = 'permissions-add-role';
    protected $noHeaderButtons = true;

    public $class = 'min-w-96 max-w-xl';

    public $model = RoleModel::class;

    public function authorize()
    {
        return auth()->user()->hasPermission('Role', PermissionTypeEnum::WRITE);
    }

    public function beforeSave()
    {
        if (!$this->model->id) {
            $enName = request('name')['en'] ?? request('name')['fr'] ?? 'role';
            $this->model->id = \Str::snake($enName) . '-' . \Str::random(3);
        }

        if (request('just_one_per_team')) {
            $this->model->max_assignments_per_team = 1;
        } else {
            $this->model->max_assignments_per_team = null;
        }
    }

    public function afterSave()
    {
        // Team roles must be clamped before roleChanged() so its cache flush covers them too.
        $this->model->clampTeamRolesHierarchyToRollFlags();

        app(PermissionCacheInvalidator::class)->roleChanged($this->model);
        RolesMatrixView::addRole($this->model->id);
    }

    public function body()
    {
        return _Rows(
            _Translatable('permissions-role-name')->name('name')->required(),
            _TranslatableEditor('permissions-role-description')->name('description')->toolbar([]),
            _Image('permissions-role-icon')->name('icon'),

            _Rows($this->extraFields()),

            _Select('permissions-profile')->name('profile')->required()->default(1)->options(
                $this->profileOptions(),
            )->overModal('profile'),

            _Rows(
                _Toggle('permissions-accept-roll-down')->name('accept_roll_to_child'),
                _Toggle('permissions-accept-roll-to-neighbours')->name('accept_roll_to_neighbourg'),
            ),

            _Toggle('permissions-just-one-per-team')
                ->name('just_one_per_team', false)
                ->default($this->model->max_assignments_per_team),

            _SubmitButton('permissions-save')->class('w-full')->closeModal()->refresh(RolesAndPermissionMatrix::ID),
        );
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
            'name' => ['required', new MaxTranslatable(250), 'unique:roles,name,' . $this->model->id],
            'description' => ['nullable', new MaxTranslatable(1000)],
            'icon' => 'nullable',
            'profile' => 'required|string|in:' . implode(',', array_keys($this->profileOptions()->all())),
            'accept_roll_to_child' => 'required|boolean',
            'accept_roll_to_neighbourg' => 'required|boolean',
        ];
    }
}
