<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Model;

class PermissionSection extends Model
{
    public function permissions()
    {
        return $this->hasMany(Permission::class, 'permission_section_id');
    }

    // CALCULATED FIELDS
    public function getPermissions()
    {
        return \Cache::remember('permissions_of_section_' . $this->id, 3600, function () {
            return $this->permissions()->get();
        });
    }

    public function hasAllPermissionsSameType($role)
    {
        $permissionType = $role->getFirstPermissionTypeOfSection($this->id);

        return $role->permissions()->forSection($this->id)
            ->wherePivot('permission_type', $permissionType)
            ->where('permission_section_id', $this->id)->count() == $this->permissions()->count();
    }
}