<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Models\Teams\PermissionSection;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\Roles\Role;
use Kompo\Table;

class PermissionSectionRolesTable extends Table
{
    protected $permissionSectionId;
    protected $permissionSection;

    protected $permissionsIds;

    public function created()
    {
        $this->permissionSectionId = $this->prop('permission_section_id');

        $this->permissionSection = PermissionSection::findOrFail($this->permissionSectionId);

        $this->permissionsIds = $this->permissionSection->permissions()->pluck('id');
    }

    public function createdDisplay()
    {
        $this->itemsWrapperClass = 'subgroup-block'.$this->permissionSectionId;

        $this->itemsWrapperStyle = 'display:none;';
    }

    public function top()
    {
        return _Flex(
            _Flex(
                _Html()->icon('icon-up')->id('subgroup-toggle'.$this->permissionSectionId),
                _Html($this->permissionSection?->name)->class('text-gray-600'),
            )->class('gap-1'),
            ...getRoles()->map(function ($role) {
                return _Panel(
                    $this->sectionCheckbox($role),
                )->id('role-permission-section-'.$role->id.'-'.$this->permissionSectionId);
            }),
        )->class('bg-level4 roles-manager-rows')->class('button-toggle' . $this->permissionSectionId)
            ->run('() => { toggleSubGroup('.$this->permissionSectionId.', "") }')->class('hover:bg-level4 cursor-pointer');
    }

    public function query()
    {
        return $this->permissionSection->permissions();
    }

    public function render($permission)
    {
        return _Flex(
            _Html($permission->permission_name),
            ...Role::all()->map(function ($role) use ($permission) {
                return _CheckboxMultipleStates($role->id . '-' . $permission->id, 
                        PermissionTypeEnum::values(),
                        PermissionTypeEnum::colors(),
                        $role->permissions->first(fn($p) => $p->id == $permission->id)?->pivot?->permission_type
                    )->class('!mb-0')
                    ->onChange(fn($e) => $e
                        ->selfPost('changeRolePermission', ['role' => $role->id, 'permission' => $permission->id])
                        ->selfGet('sectionCheckbox', ['role' => $role->id])->inPanel('role-permission-section-'.$role->id.'-'.$this->permissionSectionId)
                    ) ;
            }),
        )->class('roles-manager-rows');
    }

    public function sectionCheckbox($role)
    {
        $role = is_string($role) ? Role::findOrFail($role) : $role;
        $checkboxName = 'permissionSection' . $role->id . '-' . $this->permissionSection->id;

        return _CheckboxMultipleStates($checkboxName, 
                PermissionTypeEnum::values(),
                PermissionTypeEnum::colors(),
                $this->permissionSection->hasAllPermissionsSameType($role) ? $role->getFirstPermissionTypeOfSection($this->permissionSectionId) : null
            )->class('!mb-0')
            ->onChange(fn($e) => $e
                ->selfPost('changeRolePermissionSection', ['role' => $role->id, 'permissionSection' => $this->permissionSectionId]) &&
                $e->run('() => {changeMultipleLinkGroupColor("'. $checkboxName .'", "'. $role->id .'", "'. $this->permissionsIds->implode(',') .'")}')
            );
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
        }

        foreach($permissions as $permission) {
            $role->createOrUpdatePermission($permission, $value);
        }
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
}
