<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Models\Teams\Roles\Role;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Teams\Cache\PermissionCacheInvalidator;

trait RoleElementsUtils
{
    public function multiSelect($defaultRolesIds = null)
    {
        return _MultiSelect()->name('roles', false)->placeholder('auth-roles')->options(
            getRoles()->mapWithKeys(fn($r) => [$r->id => _Html($r->name)->attr(['data-role-id' => $r->id, 'data-lowercase-name' => strtolower($r->name)])])->toArray()
        )->default($defaultRolesIds ?? [])
            // Debounce so rapid multi-select changes merge into a single
            // getRoleUpdate AJAX. Without this, sequential responses overwrite
            // each other in #hidden-roles and earlier templates get lost,
            // leaving spinners stuck "loading forever".
            ->debounce(350)
            ->onChange(
                fn($e) => $e->run('precreateRoleVisuals')
                    && $e->selfPost('getRoleUpdate')->inPanel('hidden-roles')->run('injectRoleContent')
            );
    }

    public function roleHeader($role, $i = 1)
    {
        return _Panel(
            _Flex(
                _Html($role?->name ?? '&nbsp;')
                    ->class('flex-1 text-center'),
                !$role ? null : _TripleDotsDropdown(
                    _Link('permissions-edit')->class('py-1 px-2')->selfGet('getRoleForm', ['id' => $role?->id])->inModal(),
                    !$role->canSeeDeletedButton() ? null : (
                        !$role->hasPendingActionsToDelete() ? _DeleteLink('permissions-delete')->class('py-1 px-2 text-red-500')->selfPost('deleteRole', ['id' => $role?->id])->refresh() :
                            _Link('permissions-delete')->class('py-1 px-2 text-red-500')
                                ->selfPost('getPendingActionsToDeleteRoleModal', ['id' => $role?->id])->inModal()
                    ),
                )->class('flex-shrink-0')->checkAuthWrite('Role'),
            )->class('h-full items-center gap-1 px-2 w-full'),
        )
        ->class('w-32 flex-shrink-0 relative bg-white h-full overflow-hidden')
        ->when($i == 0, fn($e) => $e->class('border-r border-gray-300'))
        ->id('role-header-' . $role?->id)
        ->attr(['data-role-id' => $role?->id]);
    }

    public function getPendingActionsToDeleteRoleModal($roleId)
    {
        return new PendingActionsToDeleteRoleModal($roleId);
    }

    /**
     * The collapsible section header — section name + edit pencil + per-role
     * aggregate widget. Lives on its own (outside PermissionSectionRolesTable)
     * so the permission rows can be lazy-loaded below it.
     *
     * Clicking the header: toggles the rows panel below + fetches the rows
     * via selfGet on first expand (subsequent toggles just slide-toggle the
     * cached DOM via toggleSubGroup; idempotent re-fetch on re-expand is
     * cheap and keeps state fresh).
     */
    public function sectionHeader($permissionSection, $rolesIds)
    {
        $roles = getRoles()->whereIn('id', $rolesIds)->values();
        $roles->each(fn($role) => $role->setRelation('permissionsTypes',
            $role->memoize('permissionsTypes', fn() => $role->permissionsTypes()->get())
        ));

        return _Flex(
            _FlexCenter(
                _Html()->icon('icon-up')->id('subgroup-toggle' . $permissionSection->id),
                _Html($permissionSection->name)->class('text-gray-600'),
                !isAppSuperAdmin() ? null : _Link()->icon('pencil')->class('right-2 top-2 absolute')
                    ->selfGet('getEditSectionInfoForm', ['section_id' => $permissionSection->id])->inModal(),
            )->class('gap-1 bg-level4 border-r border-level1/30 relative'),
            ...$roles->map(fn($role) => _Rows(
                $this->sectionCheckbox($role, $permissionSection,
                    explode('|', $role->permissionsTypes->where('permission_section_id', $permissionSection->id)->first()?->permission_type ?: '0')
                ),
            )->class('w-32 flex-shrink-0 items-center')->attr(['data-role-id' => $role->id])),
        )
        ->attr(['data-permission-section-id' => $permissionSection->id])
        ->class('bg-level4 roles-manager-rows w-max')
        ->class('button-toggle' . $permissionSection->id)
        ->class('hover:bg-level4 cursor-pointer');
        // Click handler (slide-toggle + lazy-load) is added by _LazyCollapsible
        // wrapping this header in RolesAndPermissionMatrix::render().
    }

    public function sectionRoleEl($role, $permission, $permissionSectionId, $permissionsIds, $default = null)
    {
        $userHasWritePermission = auth()->user()->hasPermission('Role', PermissionTypeEnum::WRITE);

        return _Rows(_CheckboxMultipleStates(
            $role->id . '-' . $permission->id,
            PermissionTypeEnum::forPermission($permission),
            PermissionTypeEnum::colorsForPermission($permission),
            $default ?? $role->getPermissionTypeByPermissionId($permission->id),
            $userHasWritePermission
        )->class('!mb-0')
            ->group($role->id . '-' . $permissionSectionId)
            ->permissionId($permission->id)
            ->when($userHasWritePermission,
                fn($el) => $el->onChange(
                    fn($e) => $e->selfPost('changeRolePermission', ['role' => $role->id, 'permission' => $permission->id])
                )
            )
        )->class('w-32 flex-shrink-0 items-center')->attr(['data-role-id' => $role->id]);
    }

    public function sectionCheckbox($role, $permissionSection, $types = [])
    {
        $role = is_string($role) ? Role::findOrFail($role) : $role;
        $checkboxName = 'permissionSection' . $role->id . '-' . $permissionSection->id;
        $userHasWritePermission = auth()->user()->hasPermission('Role', PermissionTypeEnum::WRITE);

        return _Rows(_CheckboxSectionMultipleStates(
            $checkboxName,
            PermissionTypeEnum::forSection($permissionSection),
            PermissionTypeEnum::colorsForSection($permissionSection),
            count($types) ? $types : $permissionSection->allPermissionsTypes($role)->toArray(),
            $userHasWritePermission
        )->class('!mb-0')
        ->group($role->id . '-' . $permissionSection->id)
        ->when($userHasWritePermission,
            fn($el) => $el->onChange(
                fn($e) => $e
                    ->selfPost('changeRolePermissionSection', [
                        'role' => $role->id,
                        'permissionSection' => $permissionSection->id,
                        'permission_name' => request('permission_name'),
                    ])->run('reduceApplyingChangesAlert')
                    && $e->run('() => { setApplyingChangesAlert(); }')
            ))
        )->attr(['data-role-id' => $role->id]);
    }

    public function deleteRole()
    {
        $role = RoleModel::findOrFail(request('id'));
        app(PermissionCacheInvalidator::class)->roleChanged($role);
        
        return response()->kompoMulti([
            response()->closeModal(),
            response()->kompoRun('() => { window.utils.setLoadingScreen() }'),
            response()->kompoRedirect(route('roles.manage')),
        ]);
    }
}
