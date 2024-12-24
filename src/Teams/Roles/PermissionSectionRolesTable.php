<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Models\Teams\PermissionSection;
use Kompo\Query;

class PermissionSectionRolesTable extends Query
{
    use RoleRequestsUtils;
    use RoleElementsUtils;

    public $paginationType = 'Scroll';
    public $perPage = 25;

    protected $permissionSectionId;
    protected $permissionSection;

    protected $permissionsIds;
    protected $roles;

    public function created()
    {
        $this->permissionSectionId = $this->prop('permission_section_id');

        $this->permissionSection = PermissionSection::findOrFail($this->permissionSectionId);

        $this->permissionsIds = $this->permissionSection->getPermissions()->pluck('id');

        $rolesIds = $this->prop('roles_ids') ? explode(',', $this->prop('roles_ids')) : null;

        $this->roles = getRoles()->when($rolesIds, fn($q) => $q->whereIn('id', $rolesIds))->values();
        $this->roles->load(['permissions' => 
            fn($q) => $q->where('permission_section_id', $this->permissionSectionId)
        ]);

        $this->onLoad(fn($e) => $e->run('() => { 
            $(".PermissionSectionRoleWrapper").css("display", "none");
        }'));
    }

    public function createdDisplay()
    {
        $this->itemsWrapperClass = 'PermissionSectionRoleWrapper mini-scroll subgroup-block'.$this->permissionSectionId;

        $this->itemsWrapperStyle = 'max-height:50vh;';
    }

    public function top()
    {
        return _Flex(
            _FlexCenter(
                _Html()->icon('icon-up')->id('subgroup-toggle'.$this->permissionSectionId),
                _Html($this->permissionSection?->name)->class('text-gray-600'),
            )->class('gap-1 bg-level4 border-r border-level1/30'),
            ...$this->roles->map(function ($role) {
                return _Rows(
                    $this->sectionCheckbox($role, $this->permissionSectionId),
                )->attr(['data-role-id' => $role->id]);
            }),
        )->attr(['data-permission-section-id' => $this->permissionSectionId])->class('bg-level4 roles-manager-rows w-max')->class('button-toggle' . $this->permissionSectionId)
            ->run('() => { toggleSubGroup('.$this->permissionSectionId.', "") }')->class('hover:bg-level4 cursor-pointer');
    }

    public function query()
    {
        return $this->permissionSection->getPermissions()
            ->when(request('permission_name'), fn($q) => $q->filter(fn($p) => str_contains(strtolower($p->permission_name), strtolower(request('permission_name')))));
    }

    public function render($permission)
    {
        return _Flex(
            _Html($permission->permission_name)->class('bg-white border-r border-gray-300'),
            ...$this->roles->map(function ($role) use ($permission) {
                return $this->sectionRoleEl($role, $permission, $this->permissionSectionId, $this->permissionsIds);
            }),
        )->class('roles-manager-rows w-max')->class($permission->object_type?->classes() ?? '')->attr(['data-permission-id' => $permission->id]);
    }
}
