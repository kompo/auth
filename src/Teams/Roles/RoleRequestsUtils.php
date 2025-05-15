<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\Roles\Role;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

trait RoleRequestsUtils
{
    // GET REQUESTS
    public function changeRolePermissionSection()
    {
        $value = (int) request('permissionSection' . request('role') . '-' . request('permissionSection'));

        $role = Role::findOrFail(request('role'));
        $permissions = Permission::where('permission_section_id', request('permissionSection'))
            ->when(request('permission_name') && request('permission_name') !== "null", fn($q) => $q->where('permission_name', 'like', wildcardSpace(request('permission_name'))))
            ->pluck('id');

        if($value) {
            $value = PermissionTypeEnum::from($value);
        }

        if (!$value) {
            $role->permissions()->detach($permissions);
        } else {
            foreach($permissions as $permission) {
                $role->createOrUpdatePermission($permission, $value);
            }
        }

        \Cache::flushTags(['permissions'], true);
    }

    public function changeRolePermission()
    {
        $value = (int) request(request('role') . '-' . request('permission'));

        if($value) {
            $value = PermissionTypeEnum::from($value);
        } 

        $role = Role::findOrFail(request('role'));

        if (!$value) {
            $role->permissions()->detach(request('permission'));
        } else{
            $role->createOrUpdatePermission(request('permission'), $value);
        }

        \Cache::flushTags(['permissions'], true);
    }

    public function getRoleForm($id = null)
    {
        return new (config('kompo-auth.role-form-namespace'))($id);
    }

    public function getRoleUpdate()
    {
        $latestRoles = session()->get('latest-roles') ?: [];
        session()->put('latest-roles', request('roles'));

        return new RoleWrap(null, [
            'roles_ids' => collect(request('roles'))->diff($latestRoles)->implode(','),
        ]);
    }
}