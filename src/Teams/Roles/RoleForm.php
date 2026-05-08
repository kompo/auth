<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Modal;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Rules\MaxTranslatable;
use Kompo\Auth\Teams\Cache\PermissionCacheInvalidator;

class RoleForm extends Modal
{
    use RoleElementsUtils;
    use RoleRequestsUtils;

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
        app(PermissionCacheInvalidator::class)->roleChanged($this->model);
    }

    public function response()
    {
        $latestRoles = collect(session()->get('latest-roles') ?: []);

        // Ensure saved role is in the selection.
        $allRoles = $latestRoles->push($this->model->id)->unique()->values()->all();
        request()->merge(['roles' => $allRoles]);

        // Force the saved role into the diff so RoleWrap renders its templates
        // (header, section aggregates, cells) for BOTH create and update.
        // Without this, updates produce an empty diff and the matrix's column
        // header keeps the old name. JS handlers then route appropriately:
        //   - update → updateExistingRoleHeaders replaces the column header.
        //   - create → precreateRoleVisuals + injectRoleContent fill placeholders.
        session()->put(
            'latest-roles',
            $latestRoles->reject(fn($id) => $id === $this->model->id)->values()->all()
        );

        return $this->getRoleUpdate();
    }

    public function body()
    {
        return _Rows(
            _Translatable('permissions-role-name')->name('name')->required(),
            _TranslatableEditor('permissions-role-description')->name('description')->toolbar([]),
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
                _SubmitButton('permissions-save')->class('w-full')
                    // 1) Submit response (RoleWrap templates) lands in #hidden-roles, modal closes.
                    ->onSuccess(fn($e) => $e->inPanel('hidden-roles')->closeModal())
                    // 2) Refresh the multiselect; ONLY when its tags reflect the new role
                    //    do we run precreate + inject, otherwise precreate reads stale tags
                    //    and never adds a column placeholder for the new role.
                    ->onSuccess(fn($e) => $e
                        ->selfGet('roleMultiSelect')
                        ->inPanel('multi-select-roles')
                        ->run('() => {
                            precreateRoleVisuals();   // add placeholders for new roles
                            injectRoleContent();      // updates existing headers + fills placeholders (polled, waits for templates)
                        }')
                    ),
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
            'name' => ['required', new MaxTranslatable(250), 'unique:roles,name,' . $this->model->id],
            'description' => ['nullable', new MaxTranslatable(1000)],
            'icon' => 'nullable',
            'profile' => 'required|string|in:' . implode(',', array_keys($this->profileOptions()->all())),
            'accept_roll_to_child' => 'required|boolean',
            'accept_roll_to_neighbourg' => 'required|boolean',
        ];
    }
}
