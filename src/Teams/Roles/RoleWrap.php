<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\Form;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionSection;

class RoleWrap extends  Form
{
    public $model = RoleModel::class;

    protected $rolesIds;

    public function created()
    {
        $this->rolesIds = explode(',', $this->prop('roles_ids'));
    }

    public function render()
    {
        $permissions = Permission::query()->with([
            'roles' => fn($q) => $q->whereIn('roles.id', $this->rolesIds)->selectRaw('roles.id'),
        ])->get();

        $roles = RoleModel::where('id', collect($this->rolesIds)->last())->get();
        $results = [];

        foreach ($roles as $role) {
            foreach ($permissions as $permission) {
                $permissionSectionId = $permission->permission_section_id;
                $permissionIds = $permission->where('permission_section_id', $permissionSectionId)->pluck('id');
                $permissionType = $permission->roles->where('id', $role->id)->first()?->pivot?->permission_type;

                $results[] = PermissionSectionRolesTable::sectionRoleEl($role, $permission, $permissionSectionId, $permissionIds, $permissionType)
                    ->attr(['data-role-example' => $role->id . '-' . $permission->id]);
            }
        }

        return _Rows(
            $results,
        )->class('opacity-0 role-wrap-example-data h-0 absolute top-0 left-0');
    }

    
    public function changeRolePermissionSection()
    {
        $value = (int) request('permissionSection' . request('role') . '-' . request('permissionSection'));

        $role = Role::findOrFail(request('role'));
        $permissionSection = PermissionSection::findOrFail(request('permissionSection'));
        $permissions = $permissionSection->permissions()->pluck('id');

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

    protected function getPermissionSectionPanelKey($role, $permissionSection)
    {
        return 'role-permission-section-'.$role->id.'-'.$permissionSection->id;
    }
}