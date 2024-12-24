<?php

namespace Kompo\Auth\Models\Teams\Roles;

use Kompo\Auth\Models\Model;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\TeamRole;

class Role extends Model
{
    protected $casts = [
        'icon' => 'array',
        'id' => 'string',
    ];

    public function save(array $options = [])
    {
        parent::save($options);

        \Cache::forget('roles');
    }

    public $incrementing = false;

    public function teamRoles()
    {
        return $this->hasMany(TeamRole::class, 'role', 'id');
    }

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
        return $this->permissions()->forSection($sectionId)->first()?->pivot?->permission_type;
    }

    public function validPermissionsQuery()
    {
        return $this->validPermissions()
            ->selectRaw(constructComplexPermissionKeySql('permission_role') . ', permission_key, permissions.id');
    }

    public function deniedPermissionsQuery()
    {
        return $this->deniedPermissions()
            ->select('permissions.id');
    }

    // SCOPES

    // ACTIONS

    public function createOrUpdatePermission($permissionId, $value)
    {
        $permission = $this->permissions()->where('permissions.id', $permissionId)->first();

        if (!$permission) {
            $this->permissions()->attach($permissionId, ['permission_type' => $value, 'added_by' => auth()->id(), 'modified_by' => auth()->id()]);
        } else {
            $this->permissions()->updateExistingPivot($permissionId, ['permission_type' => $value, 'modified_by' => auth()->id()]);
        }
    }

    public static function getOrCreate($name) {
        $role = self::where('id', $name)->first();

        if (!$role) {
            $role = new static;
            $role->id = $name;
            $role->name = ucfirst($name);
            $role->save();
        }

        return $role;
    }
}