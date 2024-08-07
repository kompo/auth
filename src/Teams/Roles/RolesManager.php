<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\Roles\Role;
use Kompo\Table;

class RolesManager extends Table
{
    public $id = 'roles-manager';
    public function top()
    {
        return _FlexBetween(
            _Html('Roles')->class('text-lg font-bold'),
            _Link('Create Role')->selfGet('getRoleForm')->inModal()
        )->class('mb-4');
    }

    public function headers()
    {
        return collect([null])->merge(Role::all())->map(function ($role) {
            return _Th($role?->name);
        })->toArray();
    }

    public function query()
    {
        return Permission::all();
    }
    
    public function render($permission)
    {

        return _TableRow(
            _Html($permission?->permission_name)->class('text-gray-600'),

            ...Role::all()->map(function ($role) use ($permission) {
                return _CheckboxMultipleStates($role->id . '-' . $permission->id, 
                        PermissionTypeEnum::values(),
                        PermissionTypeEnum::colors(),
                        $role->permissions->first(fn($p) => $p->id == $permission->id)?->pivot?->permission_type
                    )
                    ->onChange(fn($e) => $e
                        ->selfPost('changeRolePermission', ['role' => $role->id, 'permission' => $permission->id])
                    ) ;
            }),
        );
    }

    public function changeRolePermission()
    {
        $value = (int) request(request('role') . '-' . request('permission'));

        if($value) {
            $value = PermissionTypeEnum::from($value);
        } 

        $role = Role::findOrFail(request('role'));

        if (!$value) {
            return $role->permissions()->detach(request('permission'));
        } 

        $role->createOrUpdatePermission(request('permission'), $value);

    }

    public function getRoleForm()
    {
        return new RoleForm();
    }

    public function js()
    {
        return <<<javascript
            function changeLinkGroupColor(optionClass)
            {
                let current = $("." + optionClass + ".perm-selected").eq(0)
                let next = current.parent().next().find("." + optionClass).eq(0)
                if (!next.length) {
                    next = $("." + optionClass).eq(0)
                }

                $("." + optionClass).addClass("hidden")
                current.removeClass("perm-selected")
                next.removeClass("hidden").addClass("perm-selected")
            }
        javascript;
    }
}