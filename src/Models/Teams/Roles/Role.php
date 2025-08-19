<?php

namespace Kompo\Auth\Models\Teams\Roles;

use Condoedge\Utils\Models\Model;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Models\Traits\BelongsToManyPivotlessTrait;
use Kompo\Auth\Teams\PermissionCacheManager;

class Role extends Model
{
    use BelongsToManyPivotlessTrait;

    protected $casts = [
        'icon' => 'array',
        'id' => 'string',
    ];

    // It's impossible to set this kind of restriction because we read the role to get the permissions it would be getting a infinite loop.
    protected $readSecurityRestrictions = false;

    public static function booted()
    {
        parent::booted();

        static::saved(function ($role) {
            $role->clearCache();
        });

        static::deleted(function ($role) {
            $role->clearCache();
        });
    }

    protected function clearCache()
    {
        app(PermissionCacheManager::class)->invalidateByChange('role_permissions_changed', [
            'role_ids' => [$this->id]
        ]);
            
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

    public function permissionsTypes()
    {
        return $this->belongsToManyPivotless(Permission::class, 'permission_role', 'role', 'permission_id')->getAllPermissionsBySections();
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
    public function getPermissionTypeByPermissionId($permissionId)
    {
        return $this->permissions->first(fn($p) => $p->id == $permissionId)?->pivot?->permission_type;
    }

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
        return $this->deniedPermissions();
    }

    // SCOPES

    // ACTIONS
    public function save(array $options = []): void
    {
        if ($this->from_system) {
            throw new \Exception(__('auth-you-cannot-update-system-role'));
        }

        parent::save($options);
    }

    public function delete()
    {
        if ($this->from_system) {
            throw new \Exception(__('auth-you-cannot-delete-system-role'));
        }

        parent::delete();
    }

    public function createOrUpdatePermission($permissionId, $value)
    {
        $permission = $this->permissions()->where('permissions.id', $permissionId)->first();

        if (!$permission) {
            $this->permissions()->attach($permissionId, ['permission_type' => $value, 'added_by' => auth()->id(), 'modified_by' => auth()->id()]);
        } else {
            $this->permissions()->updateExistingPivot($permissionId, ['permission_type' => $value, 'modified_by' => auth()->id()]);
        }
    }

    public static function getOrCreate($name)
    {
        $role = self::where('id', $name)->first();

        if (!$role) {
            $role = new static;
            $role->id = $name;
            $role->name = ucfirst($name);
            $role->from_system = true; // Mark as system role
            $role->save();
        }

        return $role;
    }
}
