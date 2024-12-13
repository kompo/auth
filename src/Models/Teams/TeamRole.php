<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Models\Model;
use Kompo\Auth\Models\Teams\BaseRoles\SuperAdminRole;
use Kompo\Auth\Models\Teams\BaseRoles\TeamOwnerRole;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\Roles\Role;
use Kompo\Auth\Models\User;

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
                $this->roleRelation->validPermissionsQuery()
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
                $this->roleRelation->deniedPermissionsQuery(),
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
        return \Cache::rememberWithTags(['permissions'], 'teamRolePermissions' . $this->id, 180, 
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
     * ! DISABLED FOR NOW. BECAUSE WE COULD'T KNOW IF WE GET A PERMISSIONS DENIED BY ONE ROLE.
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

        $roles = Role::whereIn('id', $teamRoles->unique('role')->pluck('role'))->get();

        // First we filter by role. We was using validPermissionsQuery of this class. But we was querying many times the same role.
        $validPermissionsQuery = $roles->reduce(function ($acc, $role) {
            return $acc->union($role->validPermissionsQuery());
        }, $roles->get(0)->validPermissionsQuery());

        // Then we filter by team role. We was using validPermissionsQuery of this class but we separated it to avoid querying the same role many times.
        $validPermissionsQuery->union(
            PermissionTeamRole::whereIn('team_role_id', $teamRoles->pluck('id'))
                ->join('permissions', 'permissions.id', '=', 'permission_team_role.permission_id')
                ->selectRaw(constructComplexPermissionKeySql('permission_team_role') . ', permission_key, permissions.id'),
        );
        
        // First we filter by role. We was using deniedPermissionsQuery of this class. But we was querying many times the same role.
        $deniedPermissionsQuery = $roles->reduce(function ($acc, $role) {
            return $acc->union($role->deniedPermissionsQuery());
        }, $roles->get(0)->deniedPermissionsQuery());

        // Then we filter by team role. We was using deniedPermissionsQuery of this class but we separated it to avoid querying the same role many times.
        $deniedPermissionsQuery->union(
            PermissionTeamRole::whereIn('team_role_id', $teamRoles->pluck('id'))
                ->select('permission_id'),
        );

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

    public function getAllHierarchyTeamsIds($search = '')
    {
        $teams = collect([$this->team->id => $this->role]);

        if ($search && !$this->team()->search($search)->exists()) {
            $teams = collect();
        }

        if ($this->getRoleHierarchyAccessBelow()) {
            $teams = $teams->union($this->team->getAllChildrenRawSolution(staticExtraSelect: [$this->role, 'role'], search: $search));
        }

        if ($this->getRoleHierarchyAccessNeighbors()) {
            $teams = $teams->union($this->team->parentTeam?->teams()?->selectRaw('id, ? as role', [$this->role])
                ->when($search, fn($q) => $q->search($search))
                ->pluck('role', 'id') ?: []);
        }

        return $teams;
    }

    public function hasAccessToTeam($teamId)
    {
        if ($this->team_id == $teamId) {
            return true;
        }

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

    public static function getParentHierarchyRole($teamId, $userId, $role = null)
    {
        return static::when($role, fn($q) => $q->where('role', $role))->where('user_id', $userId)->get()->first(
            fn($teamRole) => $teamRole->hasAccessToTeam($teamId)
        );
    }

    public static function getOrCreateForUser($teamId, $userId, $role)
    {
        $teamRole = static::where('team_id', $teamId)
            ->where('user_id', $userId)
            ->where('role', $role)
            ->first();

        if (!$teamRole) {
            $parentHierarchyRole = static::getParentHierarchyRole($teamId, $userId, $role);
            
            $teamRole = $parentHierarchyRole?->createChildForHierarchy($teamId, $userId);
        }

        return $teamRole;
    }

    public function createChildForHierarchy($teamId)
    {
        if (!$this->hasAccessToTeam($teamId)) {
            abort(403);
        }

        $teamRole = new static;
        $teamRole->team_id = $teamId;
        $teamRole->user_id = $this->user_id;
        $teamRole->role = $this->role;
        $teamRole->parent_team_role_id = $this->id;
        $teamRole->role_hierarchy = RoleHierarchyEnum::DIRECT;
        $teamRole->save();

        return $teamRole;
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
