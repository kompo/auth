<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Models\Model;
use Kompo\Auth\Models\Teams\BaseRoles\SuperAdminRole;
use Kompo\Auth\Models\Teams\BaseRoles\TeamOwnerRole;
use Kompo\Auth\Models\Teams\Permission;

class TeamRole extends Model
{
    use \Kompo\Auth\Models\Teams\BelongsToTeamTrait;
    use \Kompo\Auth\Models\Traits\BelongsToUserTrait;

    public const ROLES_DELIMITER = ',';

    protected $casts = [
        'role_hierarchy' => RoleHierarchyEnum::class,
    ];

    public static function booted()
    {
        static::addGlobalScope('withoutTerminated', function ($builder) {
            $builder->whereNull('terminated_at');
        });

        static::addGlobalScope('withoutSuspended', function ($builder) {
            $builder->whereNull('suspended_at');
        });
    }

    /* RELATIONS */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class)->withPivot('permission_type');
    }

    public function validPermissions()
    {
        return $this->permissions()->wherePivot('permission_type', '!=', PermissionTypeEnum::DENY);
    }

    public function deniedPermissions()
    {
        return $this->permissions()->wherePivot('permission_type', PermissionTypeEnum::DENY);
    }

    public function roleRelation()
    {
        return $this->belongsTo(RoleModel::getClass(), 'role');
    }

    /* SCOPES */
    public function scopeRelatedToTeam($query, $teamId = null)
    {
        $query->when($teamId, fn($q) => $q->where('team_id', $teamId));
    }

    public function validPermissionsQuery()
    {
        return $this->validPermissions()->selectRaw(constructComplexPermissionKeySql('permission_team_role'). ', permission_key, permissions.id')
            ->union($this->roleRelation->validPermissions()->selectRaw(constructComplexPermissionKeySql('permission_role'). ', permission_key, permissions.id')
        );
    }

    public function deniedPermissionsQuery()
    {
        return $this->deniedPermissions()->select('permissions.id')
            ->union($this->roleRelation->deniedPermissions()->select('permissions.id')
        );
    }

    public function getAllPermissionsKeysQuery()
    {
        return $this->validPermissionsQuery()
            ->whereNotIn('permissions.id', 
                $this->deniedPermissionsQuery()->pluck('permissions.id')
            )->distinct();
    }

    public function getAllPermissionsKeys()
    {
        return \Cache::remember('teamRolePermissions'.$this->id, 180, 
            fn() => $this->getAllPermissionsKeysQuery()->pluck('complex_permission_key')
        );
    }

    public function hasPermission($permissionKey, PermissionTypeEnum $type = PermissionTypeEnum::ALL)
    {
        // Permission::whereIn('permissions.id', TeamRole::getAllPermissionsKeysForMultipleRolesQuery($this->user->activeTeamRoles)
        //     ->pluck('id'))->where('permission_key', $permissionKey)->first()?->permission_key;
        return $this->getAllPermissionsKeys()->first(fn($pk) => getPermissionKey($pk) == $permissionKey && PermissionTypeEnum::hasPermission(getPermissionType($pk), $type));
    }

    public static function getAllPermissionsKeysForMultipleRolesQuery($teamRoles)
    {
        if (!$teamRoles->count()) {
            return Permission::whereRaw('1=0');
        }

        return $teamRoles->reduce(fn($acc, $teamRole) => $acc->union($teamRole->validPermissionsQuery()), $teamRoles[0]->validPermissionsQuery())
                ->whereNotIn('permissions.id', 
                    $teamRoles->reduce(fn($acc, $teamRole) => $acc->union($teamRole->deniedPermissionsQuery()), $teamRoles[0]->deniedPermissionsQuery())->select('permissions.id')
                )->distinct('complex_permission_key');
    }

    public static function getAllPermissionsKeysForMultipleRoles($teamRoles)
    {
        return static::getAllPermissionsKeysForMultipleRolesQuery($teamRoles)->pluck('complex_permission_key');
    }

    /* CALCULATED FIELDS */
    public function getRoleName()
    {
        $roleClass = $this->getRelatedRoleClass();

        return $roleClass::ROLE_NAME;
    }

    public function getRelatedRoleClass()
    {
        return static::getAllRoleClasses()->first(fn($class) => $this->role == $class::ROLE_KEY);
    }

    public function getTeamAndRoleLabel()
    {
        return $this->team->team_name.' - '.$this->getRoleName();
    }

    public static function getUsableRoleClasses()
    {
        $appRolesDir = app_path('Models/Roles');

        $allRoles = collect([
            isSuperAdmin() ? SuperAdminRole::class : null,
            //TeamOwnerRole::class, //there can be only one team owner
        ])->filter();

        if (is_dir($appRolesDir)) {

            $appRoles = collect(\File::allFiles($appRolesDir))
                ->map(fn($file) => 'App\\Models\\Roles\\'.$file->getFilenameWithoutExtension());

            $allRoles = $allRoles->concat($appRoles);
        }

        return $allRoles;
    }

    public static function getAllRoleClasses()
    {
        return static::getUsableRoleClasses()->push(TeamOwnerRole::class);
    }

    public static function baseRoles()
    {
        return [
            SuperAdminRole::class,
            TeamOwnerRole::class,
        ];
    }

    public static function teamRoleRules()
    {
        if (!config('kompo-auth.team_hierarchy_roles')) {
            $rules['role'] = ['required', 'array', 'in:'.implode(',', TeamRole::getUsableRoleClasses()->map(fn($c) => $c::ROLE_KEY)->toArray())];
        } else {
            $rules['multi_roles'] = ['required'];
        }

        return $rules;
    }

    public function getRoleHierarchyAccessDirect()
    {
        return $this->role_hierarchy->accessGrant();
    }

    public function getRoleHierarchyAccessBelow()
    {
        return $this->role_hierarchy->accessGrantBelow();
    }

    public function getRoleHierarchyAccessNeighbors()
    {
        return $this->role_hierarchy->accessGrantNeighbours();
    }

    public function getStatusAttribute()
    {
        return TeamRoleStatusEnum::getFromTeamRole($this);
    }

    public function getAllTeamsWithAccess()
    {
        $teams = collect([$this->team->id]);

        if ($this->getRoleHierarchyAccessBelow()) {
            $teams = $teams->concat($this->team->getAllChildrenRawSolution());
        }

        if ($this->getRoleHierarchyAccessNeighbors()) {
            $teams = $teams->concat($this->team->parentTeam?->teams()?->pluck('id') ?: []);
        }

        return $teams;
    }

    public function hasAccessToTeam($teamId)
    {
        if ($this->getRoleHierarchyAccessBelow() && $this->team->hasChildrenIdRawSolution($teamId)) {
            return true;
        }

        if ($this->getRoleHierarchyAccessNeighbors() && $this->team->parentTeam->teams()->where('id', $teamId)->exists()) {
            return true;
        }

        return false;
    }

    /* ACTIONS */
    public function terminate()
    {
        $this->terminated_at = now();
        $this->save();
    }

    public function suspend()
    {
        $this->suspended_at = now();
        $this->save();
    }

    public function removeSuspention()
    {
        $this->suspended_at = null;
        $this->save();
    }

    public function deleteAsignation()
    {
        $this->delete();
    }

    /* ELEMENTS */
    public static function buttonGroupField($label = 'Role')
    {
        $selectEl = config('kompo-auth.multiple_roles_per_team') ? _MultiSelect($label) : _Select($label);

        return $selectEl->name('role')
            ->options(
                static::buttonOptions()
            );
    }

    public static function buttonOptions()
    {
        return static::getUsableRoleClasses()->mapWithKeys(function ($roleClass) {
            return [
                $roleClass::ROLE_KEY => _Rows(
                    _Html($roleClass::ROLE_NAME)->class('text-sm text-gray-600'),
                    _Html($roleClass::ROLE_DESCRIPTION)->class('mt-2 text-xs text-gray-600'),
                )->class('p-4')
            ];
        });
    }

    public static function roleHierarchySelect()
    {
        return _Select('Role hierarchy')->name('role_hierarchy')
            ->options(
                static::roleHierarchyOptions()
            );
    }

    public static function roleHierarchyOptions()
    {
        return RoleHierarchyEnum::optionsWithLabels();
    }

    public function statusPill()
    {
        return _Pill($this->status->label())->class($this->status->color());
    }
}
