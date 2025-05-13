<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Models\Teams\Roles\Role;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

trait RoleElementsUtils
{
    public function multiSelect($defaultRolesIds = null)
    {
        return _MultiSelect()->name('roles', false)->placeholder('auth-roles')->options(
            getRoles()->mapWithKeys(fn($r) => [$r->id => _Html($r->name)->attr(['data-role-id' => $r->id])])->toArray()
        )->default($defaultRolesIds ?? [])
            ->onChange(
                fn($e) => $e->run('precreateRoleVisuals')
                    && $e->selfPost('getRoleUpdate')->inPanel('hidden-roles')->run('injectRoleContent')
            );
    }

    public function roleHeader($role, $i = 1)
    {
        return _Panel(
            _FlexCenter(
                _Html($role?->name ?? '&nbsp;'),
                !$role ? null : _TripleDotsDropdown(
                    _Link('permissions-edit')->class('py-1 px-2')->selfGet('getRoleForm', ['id' => $role?->id])->inModal()
                )->class('absolute right-1'),
            )->class('h-full gap-2 w-full'),
        )->class('w-full relative bg-white h-full')->when($i == 0, fn($e) => $e->class('border-r border-gray-300'))->id('role-header-' . $role?->id)->attr(['data-role-id' => $role?->id]);
    }

    public function sectionRoleEl($role, $permission, $permissionSectionId, $permissionsIds, $default = null)
    {
        $checkboxName = 'permissionSection' . $role->id . '-' . $permissionSectionId;

        return _Rows(_CheckboxMultipleStates(
            $role->id . '-' . $permission->id,
            PermissionTypeEnum::values(),
            PermissionTypeEnum::colors(),
            $default ?? $role->getPermissionTypeByPermissionId($permission->id)
        )->class('!mb-0')
            ->onChange(
                fn($e) => $e
                    ->selfPost('changeRolePermission', ['role' => $role->id, 'permission' => $permission->id]) &&
                    $e->run('() => {checkMultipleLinkGroupColor("' . $checkboxName . '", "' . $role->id . '", "' . collect($permissionsIds)->implode(',') . '")}')
            ))->attr(['data-role-id' => $role->id]);
    }

    public function sectionCheckbox($role, $permissionSection, $types = [])
    {
        $role = is_string($role) ? Role::findOrFail($role) : $role;
        $checkboxName = 'permissionSection' . $role->id . '-' . $permissionSection->id;

        return _Rows(_CheckboxSectionMultipleStates(
            $checkboxName,
            PermissionTypeEnum::values(),
            PermissionTypeEnum::colors(),
            count($types) ? $types : $permissionSection->allPermissionsTypes($role)->toArray()
        )->class('!mb-0')
            ->onChange(
                fn($e) => $e
                    ->selfPost('changeRolePermissionSection', ['role' => $role->id, 'permissionSection' => $permissionSection->id, 'permission_name' => request('permission_name')]) &&
                    $e->run('() => {changeMultipleLinkGroupColor("' . $checkboxName . '", "' . $role->id . '", "' . $permissionSection->getPermissions()->pluck('id')->implode(',') . '")}')
            ))->attr(['data-role-id' => $role->id]);
    }
}
