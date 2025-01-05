<?php

namespace Kompo\Auth\Models\Teams\Roles;

use Kompo\Auth\Models\Model;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Models\Traits\BelongsToManyPivotlessTrait;

class Role extends Model
{
    use BelongsToManyPivotlessTrait;

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
        return $this->deniedPermissions()
            ->select('permissions.id');
    }

    public function isAvailableToAssign($teamId, $userId = null)
    {
        if (!$this->just_one_person_per_team) {
            return true;
        }

        return TeamRole::thereAreMoreRoleAssignsThan($this->id, 0)
            ->when(fn($q) => $q->where('user_id', '!=', $userId))
            ->where('team_id', $teamId)->count() == 0 && $this->extraValidationAvailableToAssign($teamId, $userId);
    }

    protected function extraValidationAvailableToAssign($teamId, $userId = null)
    {
        return true;
    }

    // SCOPES

    // ACTIONS

    public function deleteAssignationsHaveMoreThan($quantity = 1, $teamId = null)
    {
        $teamsIds = $teamId ? [$teamId] : 
            TeamRole::thereAreMoreRoleAssignsThan($this->id, $quantity)->select('team_id')->pluck('team_id');

        TeamRole::where('role', $this->id)->whereIn('team_id', $teamsIds)->delete();
        $this->extraDeleteAssignations($quantity, $teamId);
    }

    protected function extraDeleteAssignations($quantity = 1, $teamId)
    {
        return;
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