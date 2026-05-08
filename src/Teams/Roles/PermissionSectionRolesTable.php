<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Models\Teams\PermissionSection;
use Kompo\Auth\Models\Teams\Roles\Role;
use Kompo\Query;

class PermissionSectionRolesTable extends Query
{
    use RoleRequestsUtils;
    use RoleElementsUtils;

    public $paginationType = 'Scroll';
    public $perPage = 10000;

    protected $permissionSectionId;
    protected $permissionSection;

    protected $permissionsIds;
    protected $roles;
    protected $permissionTypeMap;

    public function created()
    {
        $this->permissionSectionId = $this->prop('permission_section_id');

        $this->permissionSection = PermissionSection::findOrFail($this->permissionSectionId);

        $this->permissionsIds = $this->permissionSection->getPermissions()->pluck('id');

        $rolesIds = $this->prop('roles_ids') ? explode(',', $this->prop('roles_ids')) : null;

        $this->roles = getRoles()->when($rolesIds, fn($q) => $q->whereIn('id', $rolesIds))->values();

        // Memoize permissionsTypes: query runs once per role across all sections (static cache by role ID)
        $this->roles->each(fn($role) => $role->setRelation('permissionsTypes',
            $role->memoize('permissionsTypes', fn() => $role->permissionsTypes()->get())
        ));

        // Build permission type lookup map: 1 raw query for all roles+sections (classMemoize)
        $roleIds = $this->roles->pluck('id');
        $this->permissionTypeMap = Role::classMemoize(
            'permission_type_map:' . $roleIds->sort()->implode(','),
            fn() => \DB::table('permission_role')
                ->whereIn('role', $roleIds)
                ->get(['permission_id', 'role', 'permission_type'])
                ->groupBy('permission_id')
                ->map(fn($items) => $items->pluck('permission_type', 'role'))
        );

        // Sections start "collapsed" because the rows panel is empty until
        // the user expands (sectionHeader's onClick fetches via getSectionRows).
        // Once loaded, toggleSubGroup slide-toggles the items wrapper directly.
        $this->id = 'permission-section-roles-table' . $this->permissionSectionId;
    }

    public function createdDisplay()
    {
        // The slide-toggle target is the OUTER wrapper in
        // RolesAndPermissionMatrix::render($section); this inner items wrapper
        // must NOT also carry .subgroup-block<id> or jQuery's slideToggle
        // would run on both elements and the animation goes inconsistent.
        $this->itemsWrapperClass = 'PermissionSectionRoleWrapper mini-scroll';
    }

    // Section header lives on the matrix (RolesAndPermissionMatrix::sectionHeader)
    // so it stays visible while the permission rows can lazy-load below it.
    public function top()
    {
        return null;
    }

    public function query()
    {
        return $this->permissionSection->getPermissions()
            ->when(request('permission_name'), fn($q) => $q->filter(fn($p) => str_contains(strtolower($p->permission_name), strtolower(request('permission_name')))));
    }

    public function render($permission)
    {
        return _Flex(
            _Rows(
                _Html($permission->permission_name),
            )->balloon($permission->permission_description, 'right')->class('bg-white border-r border-gray-300 flex-row balloon-w-150')->balloonOver()
            ->when(isAppSuperAdmin(), fn($el) => $el->selfGet('getEditPermissionInfoForm', ['permission_id' => $permission->id])->inModal()),
            ...$this->roles->map(function ($role) use ($permission) {
                return $this->sectionRoleEl($role, $permission, $this->permissionSectionId, $this->permissionsIds, $this->permissionTypeMap[$permission->id][$role->id] ?? null);
            }),
        )->class('roles-manager-rows w-max')->class($permission->object_type?->classes() ?? '')->attr(['data-permission-id' => $permission->id]);
    }

    public function getEditPermissionInfoForm($permissionId)
    {
        return new EditPermissionInfo($permissionId, [
            'refresh_id' => $this->id,
        ]);
    }
}
