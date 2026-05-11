<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\Roles\Role;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Teams\Cache\PermissionCacheInvalidator;

trait RoleRequestsUtils
{
    // GET REQUESTS
    public function changeRolePermissionSection()
    {
        if (!auth()->user()->hasPermission('Role', PermissionTypeEnum::WRITE)) {
            abort(403, 'Unauthorized');
        }

        $value = (int) request('permissionSection' . request('role') . '-' . request('permissionSection'));

        $role = Role::findOrFail(request('role'));

        // Pull each permission's `supported_types` so we can cap per row —
        // the UI narrows the cycle but a stale POST might still send an
        // unsupported type.
        $permissions = Permission::where('permission_section_id', request('permissionSection'))
            ->when(request('permission_name') && request('permission_name') !== "null", fn($q) => $q->where('permission_name', 'like', wildcardSpace(request('permission_name'))))
            ->get(['id', 'supported_types']);

        $requestedType = $value ? PermissionTypeEnum::tryFrom($value) : null;

        if ($requestedType === null) {
            $role->permissions()->detach($permissions->pluck('id'));
        } else {
            foreach ($permissions as $permission) {
                $capped = $this->capToSupportedTypes($requestedType, $permission);
                if ($capped === null) {
                    $role->permissions()->detach([$permission->id]);
                } else {
                    $role->createOrUpdatePermission($permission->id, $capped, false);
                }
            }
        }

        app(PermissionCacheInvalidator::class)->rolePermissionsChanged([$role->id]);
    }

    public function changeRolePermission()
    {
        if (!auth()->user()->hasPermission('Role', PermissionTypeEnum::WRITE)) {
            abort(403, 'Unauthorized');
        }

        $value = (int) request(request('role') . '-' . request('permission'));
        $requestedType = $value ? PermissionTypeEnum::tryFrom($value) : null;

        $role = Role::findOrFail(request('role'));

        if ($requestedType === null) {
            $role->permissions()->detach(request('permission'));
        } else {
            $permission = Permission::findOrFail(request('permission'));
            $capped = $this->capToSupportedTypes($requestedType, $permission);

            if ($capped === null) {
                $role->permissions()->detach([$permission->id]);
            } else {
                $role->createOrUpdatePermission($permission->id, $capped, false);
            }
        }

        app(PermissionCacheInvalidator::class)->rolePermissionsChanged([$role->id]);
    }

    /**
     * Clamp the requested type to the permission's `supported_types` bitmask.
     * Returns the requested type if supported, the largest supported type
     * below it (READ < WRITE < ALL), or `null` when nothing is at-or-below.
     * `DENY` is always supported (separate axis).
     */
    protected function capToSupportedTypes(PermissionTypeEnum $requested, Permission $permission): ?PermissionTypeEnum
    {
        if ($permission->supportsType($requested)) {
            return $requested;
        }

        if ($requested === PermissionTypeEnum::DENY) {
            return $requested;
        }

        // Walk down the CRUD ladder ALL → WRITE → READ until we find a supported case.
        foreach ([PermissionTypeEnum::ALL, PermissionTypeEnum::WRITE, PermissionTypeEnum::READ] as $candidate) {
            if ($candidate->value > $requested->value) {
                continue;
            }
            if ($permission->supportsType($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function getRoleForm($id = null)
    {
        if (!auth()->user()->hasPermission('Role', PermissionTypeEnum::WRITE)) {
            abort(403, 'Unauthorized');
        }

        return new (config('kompo-auth.role-form-namespace'))($id);
    }

    public function getEditSectionInfoForm()
    {
        $sectionId = request('section_id') ?? ($this->permissionSectionId ?? null);
        abort_unless($sectionId, 400);

        return new EditPermissionSectionInfo($sectionId, [
            'refresh_id' => $this->id ?? 'roles-manager-matrix',
        ]);
    }


    public function getRoleUpdate()
    {
        if (!auth()->user()->hasPermission('Role', PermissionTypeEnum::WRITE)) {
            abort(403, 'Unauthorized');
        }

        $latestRoles = session()->get('latest-roles') ?: [];
        session()->put('latest-roles', request('roles'));

        return new RoleWrap(null, [
            'roles_ids' => collect(request('roles'))->diff($latestRoles)->implode(','),
        ]);
    }
}
