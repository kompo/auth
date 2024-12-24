<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\Form;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionSection;

class RoleWrap extends Form
{
    use RoleRequestsUtils;
    use RoleElementsUtils;

    public $model = RoleModel::class;
    protected $rolesIds;

    protected $permissionSections;

    public function created()
    {
        $this->rolesIds = explode(',', $this->prop('roles_ids'));

        $this->permissionSections = PermissionSection::with([
            'permissions' => fn($q) => $q->select('id'),
        ])->get();
    }

    public function render()
    {
        $permissions = $this->getPermissions();
        $roles = $this->getRoles();
        $results = [];

        foreach ($roles as $role) {
            $results = array_merge($results, $this->processRole($role, $permissions));
        }

        return $this->renderResults($results);
    }

    protected function getPermissions()
    {
        return Permission::query()->with([
            'roles' => fn($q) => $q->whereIn('roles.id', $this->rolesIds)->selectRaw('roles.id'),
        ])->get();
    }

    protected function getRoles()
    {
        \DB::statement("set sql_mode=''");

        return RoleModel::whereIn('id', collect($this->rolesIds))
        ->with([
            'permissions' => fn($q) => $q->selectRaw('
                CONCAT_WS("|", 
                    GROUP_CONCAT(permission_role.permission_type SEPARATOR "|"), 
                    CASE 
                        WHEN (' . \DB::table('permission_sections')
                            ->selectRaw('COUNT(permissions.id) != COUNT(permissions2.id)')
                            ->whereColumn('permission_sections.id', 'permissions.permission_section_id')
                            ->leftJoin('permissions as permissions2', 'permission_sections.id', '=', 'permissions2.permission_section_id')
                            ->groupBy('permission_sections.id')
                            ->limit(1)
                            ->toRawSql() . ') 
                        THEN "0" 
                        ELSE NULL
                    END
                ) as permission_type, 
                permission_section_id, 
                COUNT(permissions.id) as role_permissions_count'
            )
            ->groupBy('permission_section_id')
        ])->get();
    }

    protected function processRole($role, $permissions)
    {
        $results = [];

        foreach ($permissions as $permission) {
            $permissionSectionId = $permission->permission_section_id;
            $permissionIds = $this->permissionSections->firstWhere('id', $permissionSectionId)?->permissions?->pluck('id') ?: [];
            $permissionType = $permission->roles->firstWhere('id', $role->id)?->pivot?->permission_type;

            $results[] = $this->sectionRoleEl($role, $permission, $permissionSectionId, $permissionIds, $permissionType)
                ->attr(['data-role-example' => $role->id . '-' . $permission->id]);
        }

        foreach ($this->permissionSections as $permissionSection) {
            $results[] = $this->sectionCheckbox(
                $role,
                $permissionSection,
                explode('|', $role->permissions->where('permission_section_id', $permissionSection->id)->first()?->permission_type ?: '0')
            )
            ->attr(['data-permission-section-example' => $role->id . '-' . $permissionSection->id]);
        }

        $results[] = $this->roleHeader($role)->attr(['data-role-header-example' => $role->id]);

        return $results;
    }

    protected function renderResults($results)
    {
        return _Rows($results)->class('opacity-0 role-wrap-example-data h-0 absolute top-0 left-0');
    }
}
