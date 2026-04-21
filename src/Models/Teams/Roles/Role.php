<?php

namespace Kompo\Auth\Models\Teams\Roles;

use Condoedge\Utils\Models\Model;
use Kompo\Auth\Facades\UserModel;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Models\Traits\BelongsToManyPivotlessTrait;
use Kompo\Auth\Teams\Cache\PermissionCacheInvalidator;
use Condoedge\Utils\Models\Traits\MemoizesResults;
use Kompo\Database\HasTranslations;

class Role extends Model
{
    use BelongsToManyPivotlessTrait;
    use MemoizesResults;
    use HasTranslations;

    protected $casts = [
        'icon' => 'array',
        'id' => 'string',
    ];

    protected $translatable = ['name', 'description'];

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
        app(PermissionCacheInvalidator::class)->roleChanged($this);
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
        return $this->memoize('permission_types_by_id', function () {
            return $this->permissions->pluck('pivot.permission_type', 'id');
        })[$permissionId] ?? null;
    }

    public function getFirstPermissionTypeOfSection($sectionId)
    {
        return $this->memoize('first_permission_type_of_section:' . $sectionId, function () use ($sectionId) {
            return $this->permissions()->forSection($sectionId)->first()?->pivot?->permission_type;
        });
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

    public function canSeeDeletedButton()
    {
        return !$this->from_system;
    }

    public function hasPendingActionsToDelete()
    {
        return $this->teamRoles()->exists();
    }

    public function pendingActionsToDeleteEls()
    {
        return _Rows(
            _Html(__('auth-with-values-there-are-team-roles-associated-to-this-role-you-cannot-delete-it', [
                'count' => $this->teamRoles()->count(),
            ])),
        );
    }

    public function getUsersWithRole($teamsIds = null)
    {
        return UserModel::whereHas('teamRoles', function ($q) use ($teamsIds) {
            $q->where('role', $this->id);
            if ($teamsIds) {
                $q->whereIn('team_id', $teamsIds);
            }
        })->get();
    }

    // SCOPES

    // ACTIONS
    public function deletable()
    {
        return true; // If authorization required we use internal system
    }

    public function save(array $options = []): void
    {
        if ($this->from_system && !$this->_bypassSecurity) {
            throw new \Exception(__('auth-you-cannot-update-system-role'));
        }

        parent::save($options);
    }

    public function delete()
    {
        if ($this->from_system && !$this->_bypassSecurity) {
            throw new \Exception(__('auth-you-cannot-delete-system-role'));
        }

        if ($this->teamRoles()->exists()) {
            throw new \Exception(__('auth-you-cannot-delete-role-with-team-roles'));
        }

        parent::delete();
    }

    public function createOrUpdatePermission($permissionId, $value, bool $invalidate = true)
    {
        $permission = $this->permissions()->where('permissions.id', $permissionId)->first();

        if (!$permission) {
            $this->permissions()->attach($permissionId, ['permission_type' => $value, 'added_by' => auth()->id(), 'modified_by' => auth()->id(), 'updated_at' => now(), 'created_at' => now()]);
        } else {
            $this->permissions()->updateExistingPivot($permissionId, ['permission_type' => $value, 'modified_by' => auth()->id(), 'updated_at' => now()]);
        }

        if ($invalidate) {
            app(PermissionCacheInvalidator::class)->rolePermissionsChanged([$this->id]);
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
            $role->systemSave();
        }

        return $role;
    }
}
