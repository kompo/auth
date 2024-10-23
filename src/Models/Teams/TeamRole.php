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

    /**
     * Get the query for valid permissions for the team role.
     * This includes permissions defined directly on the team role and those inherited from the role relation.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function validPermissionsQuery()
    {
        return $this->validPermissions()
            ->selectRaw(constructComplexPermissionKeySql('permission_team_role') . ', permission_key, permissions.id')
            ->union(
                $this->roleRelation->validPermissions()
                    ->selectRaw(constructComplexPermissionKeySql('permission_role') . ', permission_key, permissions.id')
            );
    }

    /**
     * Get the query for denied permissions for the team role.
     * This includes permissions denied directly on the team role and those inherited from the role relation.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function deniedPermissionsQuery()
    {
        return $this->deniedPermissions()
            ->select('permissions.id')
            ->union(
                $this->roleRelation->deniedPermissions()
                    ->select('permissions.id')
            );
    }

    /**
     * Get the query for all permission keys excluding the denied ones.
     * If the user has many team roles we should merge it, so we can get all denied permissions.
     * ! DISABLED FOR NOW. BECAUSE WE COULD'T KNOW IF WE GET A PERMISSIONS DENIED BY ONE ROLE.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function getAllPermissionsKeysQuery()
    {
        return $this->validPermissionsQuery()
            ->whereNotIn('permissions.id', 
                $this->deniedPermissionsQuery()->pluck('permissions.id')
            )->distinct();
    }

    /**
     * Get all permission keys excluding the denied ones, cached for 180 minutes.
     * If the user has many team roles we should merge it, so we can get all denied permissions.
     * ! DISABLED FOR NOW. BECAUSE WE COULD'T KNOW IF WE GET A PERMISSIONS DENIED BY ONE ROLE.
     *
     * @return \Illuminate\Support\Collection
     */
    private function getAllPermissionsKeys()
    {
        return \Cache::remember('teamRolePermissions' . $this->id, 180, 
            fn() => $this->getAllPermissionsKeysQuery()->pluck('complex_permission_key')
        );
    }

    /**
     * Check if the team role denies a specific permission.
     *
     * @param string $permissionKey
     * @return bool
     */
    public function denyingPermission($permissionKey)
    {
        return $this->deniedPermissionsQuery()
            ->where('permission_key', $permissionKey)
            ->exists();
    }

    /**
     * Check if the team role has a specific permission.
     *
     * @param string $permissionKey
     * @param PermissionTypeEnum $type
     * @return bool
     */
    public function hasPermission($permissionKey, PermissionTypeEnum $type = PermissionTypeEnum::ALL)
    {
        return $this->getAllPermissionsKeys()
            ->first(fn($pk) => getPermissionKey($pk) == $permissionKey && PermissionTypeEnum::hasPermission(getPermissionType($pk), $type));
    }

    /**
     * Get the query for all permission keys for multiple team roles, excluding the denied ones.
     *
     * @param \Illuminate\Support\Collection $teamRoles
     * @return \Illuminate\Database\Query\Builder
     */
    public static function getAllPermissionsKeysForMultipleRolesQuery($teamRoles)
    {
        if (!$teamRoles->count()) {
            return Permission::whereRaw('1=0')->selectRaw('0 as complex_permission_key');
        }

        $validPermissionsQuery = $teamRoles->reduce(function ($acc, $teamRole) {
            return $acc->union($teamRole->validPermissionsQuery());
        }, $teamRoles->get(0)->validPermissionsQuery());
        
        $deniedPermissionsQuery = $teamRoles->reduce(function ($acc, $teamRole) {
            return $acc->union($teamRole->deniedPermissionsQuery());
        }, $teamRoles->get(0)->deniedPermissionsQuery());
        
        return $validPermissionsQuery
            ->whereNotIn('permissions.id', $deniedPermissionsQuery->select('permissions.id'))
            ->distinct('complex_permission_key');
    }

    /**
     * Get all permission keys for multiple team roles, excluding the denied ones.
     *
     * @param \Illuminate\Support\Collection $teamRoles
     * @return \Illuminate\Support\Collection
     */
    public static function getAllPermissionsKeysForMultipleRoles($teamRoles)
    {
        return static::getAllPermissionsKeysForMultipleRolesQuery($teamRoles)
            ->pluck('complex_permission_key');
    }

    /* CALCULATED FIELDS */
    public function getRoleName()
    {
        return $this->roleRelation?->name ?: $this->role;
    }

    public function getTeamAndRoleLabel()
    {
        return $this->team->team_name.' - '.$this->getRoleName();
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

    /**
     * Getting all teams that the team role has access to.
     * The team role could have hierarchy access to multiple teams (neighbors, children, etc).
     */
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
