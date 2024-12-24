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
        return \Cache::rememberWithTags(['permissions'], 'permissions_of_section_' . $this->id, 180, function () {
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

    public function allPermissionsTypes($role)
    {
        $fullFilled = $this->hasAllPermissions($role);
        
        $types = $role->permissions()->forSection($this->id)
            ->pluck('permission_type')
            ->unique()
            ->values();

        if (!$fullFilled) {
            $types->push(0);
        }

        return $types;
    }

    public function hasAllPermissions($role)
    {
        return $role->permissions()->forSection($this->id)->count() == $this->permissions()->count();
    }
}