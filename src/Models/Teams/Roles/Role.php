<?php

namespace Kompo\Auth\Models\Teams\Roles;

use Kompo\Auth\Models\Model;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

class Role extends Model
{
    protected $casts = [
        'icon' => 'array',
        'id' => 'string',
    ];

    public $incrementing = false;

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'permission_role', 'role', 'permission_id')->withPivot('permission_type');
    }

    public function validPermissions()
    {
        return $this->permissions()->wherePivot('permission_type', '!=', PermissionTypeEnum::DENY);
    }

    public function deniedPermissions()
    {
        return $this->permissions()->wherePivot('permission_type', PermissionTypeEnum::DENY);
    }

    // CALCULATED FIELDS 
    public function getFirstPermissionTypeOfSection($sectionId)
    {
        return $this->permissions()->forSection($sectionId)->first()->pivot->permission_type;
    }

    // SCOPES

    // ACTIONS

    public function createOrUpdatePermission($permissionId, $value)
    {
        $permission = $this->permissions()->where('permissions.id', $permissionId)->first();

        if (!$permission) {
            $this->permissions()->attach($permissionId, ['permission_type' => $value]);
        } else {
            $this->permissions()->updateExistingPivot($permissionId, ['permission_type' => $value]);
        }
    }
}