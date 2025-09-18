<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Facades\RoleModel;
use Condoedge\Utils\Models\Model;
use Illuminate\Support\Facades\Log;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\Roles\Role;
use Kompo\Auth\Teams\PermissionCacheManager;
use Kompo\Auth\Teams\TeamHierarchyService;

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
        static::saved(function ($teamRole) {
            $teamRole->clearCache();
        });

        static::deleted(function ($teamRole) {
            $teamRole->clearCache();
        });
    }

    protected function clearCache()
    {
        // Invalidate permissions cache
        app(PermissionCacheManager::class)->invalidateByChange('team_role_changed', [
            'user_ids' => [$this->user_id]
        ]);
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
        // We need to use the withoutGlobalScope because it's required to get the permissions so we would be getting an infinite loop.
        // The user shouldn't get the TeamRole if not allowed so he won't access here if he doesn't have the permission.
        return $this->belongsTo(RoleModel::getClass(), 'role')->withoutGlobalScope('authUserHasPermissions');
    }

    public function team()
    {
        // We need to use the withoutGlobalScope because it's required to get the permissions so we would be getting an infinite loop.
        // The user shouldn't get the TeamRole if not allowed so he won't access here if he doesn't have the permission.
        return $this->belongsTo(config('kompo-auth.team-model-namespace'))->withoutGlobalScope('authUserHasPermissions');
    }

    /* SCOPES */
    public function scopeRelatedToTeam($query, $teamId = null)
    {
        $query->when($teamId, fn($q) => $q->where('team_id', $teamId));
    }

    public function scopeSearch($query, $search)
    {
        $query->whereHas('user', fn($q) => $q->search($search))
            ->orWhereHas('team', fn($q) => $q->search($search))
            ->orWhereHas('roleRelation', fn($q) => $q->search($search));
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
            )->getQuery();
    }

    /**
     * Get the query for denied permissions for the team role.
     * This includes permissions denied directly on the team role and those inherited from the role relation.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function deniedPermissionsQuery()
    {
        return Permission::fromSub($this->deniedPermissions()->getQuery()
            ->selectRaw('permissions.id, permissions.permission_key, permissions.deleted_at')
            ->unionAll(
                $this->roleRelation->deniedPermissionsQuery()->selectRaw('permissions.id, permissions.permission_key, permissions.deleted_at'),
            ), 'permissions')->getQuery();
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
        return Permission::fromSub($this->validPermissionsQuery()->getQuery()
            ->whereNotIn(
                'permissions.id',
                $this->deniedPermissionsQuery()->pluck('permissions.id')
            )->distinct(), 'permissions')->getQuery();
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
        return \Cache::rememberWithTags(
            ['permissions'],
            'teamRolePermissions' . $this->id,
            180,
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

        $roles = Role::whereIn('roles.id', $teamRoles->unique('role')->pluck('role'))->get();

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
            return $acc->union($role->deniedPermissionsQuery()->select('permissions.id'));
        }, $roles->get(0)->deniedPermissionsQuery()->select('permissions.id'));

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
        return $this->team->team_name . ' - ' . $this->getRoleName();
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
        $hierarchyService = app(TeamHierarchyService::class);
        $teams = collect([$this->team->id]);

        if ($this->getRoleHierarchyAccessBelow()) {
            $descendants = $hierarchyService->getDescendantTeamIds($this->team->id);
            $teams = $teams->concat($descendants);
        }

        if ($this->getRoleHierarchyAccessNeighbors()) {
            $siblings = $hierarchyService->getSiblingTeamIds($this->team->id);
            $teams = $teams->concat($siblings);
        }

        return $teams;
    }

    public function getAllHierarchyTeamsIds($search = '', $limit = null)
    {
        $hierarchyService = app(TeamHierarchyService::class);
        $teams = collect([$this->team->id => $this->role]);

        if ($search && !str_contains(strtolower($this->team->team_name), strtolower($search))) {
            $teams = collect();
        }

        if ($this->getRoleHierarchyAccessBelow()) {
            $descendantsWithRole = $hierarchyService->getDescendantTeamsWithRole(
                $this->team->id,
                $this->role,
                $search,
                $limit,
            );

            $teams = $teams->union($descendantsWithRole);
        }

        if ($limit && $teams->count() >= $limit) {
            return $teams;
        }

        if ($this->getRoleHierarchyAccessNeighbors()) {
            $siblings = $hierarchyService->getSiblingTeamIds($this->team->id, $search, $limit);

            $siblingsWithRole = $siblings->mapWithKeys(fn($id) => [$id => $this->role]);
            $teams = $teams->union($siblingsWithRole);
        }

        return $teams;
    }

    public function getAccessibleTeamsOptimized()
    {
        $cacheKey = "team_role_accessible.{$this->id}";

        return \Cache::rememberWithTags(['permissions-v2'], $cacheKey, 900, function () {
            $teams = collect([$this->team_id]);
            $hierarchyService = app(TeamHierarchyService::class);

            // Use batch operations for hierarchy
            if ($this->getRoleHierarchyAccessBelow()) {
                $descendants = $hierarchyService->getDescendantTeamIds($this->team_id);
                $teams = $teams->concat($descendants);
            }

            if ($this->getRoleHierarchyAccessNeighbors()) {
                $siblings = $hierarchyService->getSiblingTeamIds($this->team_id);
                $teams = $teams->concat($siblings);
            }

            return $teams->unique()->values();
        });
    }

    public function hasAccessToTeam($teamId)
    {
        if ($this->team_id == $teamId) {
            return true;
        }

        $hierarchyService = app(TeamHierarchyService::class);

        if ($this->getRoleHierarchyAccessBelow() && $hierarchyService->isDescendant($this->team->id, $teamId)) {
            return true;
        }

        if (
            $this->getRoleHierarchyAccessNeighbors()
            && $hierarchyService->getSiblingTeamIds($this->team->id)->contains($teamId)
        ) {
            return true;
        }

        return false;
    }

    public function hasAccessToTeamOfMany($teamIds)
    {
        return collect($teamIds)->contains(fn($teamId) => $this->hasAccessToTeam($teamId));
    }

    /* ACTIONS */
    public function terminate()
    {
        $this->terminated_at = now();
        $this->deleted_at = now();
        $this->save();
    }

    public function suspend()
    {
        $this->suspended_at = now();
        $this->deleted_at = now();
        $this->save();
    }

    public function removeSuspention()
    {
        $this->suspended_at = null;
        $this->deleted_at = null;
        $this->save();
    }

    public function save(array $options = []): void
    {
        if (!$this->id && static::exceedsRoleLimit($this->role, $this->team_id)) {
            abort(403, __('auth.with-values.role-limit-exceeded', ['role' => $this->roleRelation->name, 'max' => $this->roleRelation->max_assignments_per_team]));

            Log::warning('Role limit exceeded for role: ' . $this->roleRelation->name . ' in team: ' . $this->team->team_name);
        }

        parent::save($options);
    }

    public static function exceedsRoleLimit($roleId, $teamId)
    {
        $role = RoleModel::find($roleId);

        if (is_null($role) || is_null($role->max_assignments_per_team)) {
            return false;
        }

        return $role->max_assignments_per_team <= static::where('role', $roleId)->where('team_id', $teamId)->asSystemOperation()->count();
    }

    public static function checkIfIsWarningEls($roleId, $teamId)
    {
        if (!$roleId || !$teamId) {
            return null;
        }

        $role = RoleModel::findOrFail($roleId);

        if (TeamRole::exceedsRoleLimit($roleId, $teamId)) {
            $baseQuery = TeamRole::where('role', $roleId)->with('user')->where('team_id', $teamId);

            return _Card(
                _Html('auth-the-role-limit-exceeded')->class('text-lg'),
                _Html('auth-you-can-terminate-assignation-to-allow-more-assignments')->class('mb-4'),

                $role->max_assignments_per_team > 1 ? _Select()->name('remove_assignation_id')
                    ->options(
                        $baseQuery->get()
                            ->pluck('user.name', 'id')
                    )
                    : _Checkbox(__('auth-with-values-terminate-assignation-to', [
                        'user_name' => $baseQuery->first()?->user?->name ?: 'N/A'
                    ]))->name('remove_assignation', false),
            )->class('border-red-600 text-red-600 bg-red-100 p-4');
        }

        return null;
    }

    public static function manageTerminateAssignation($removeAssignation, $removeAssignationId, $roleId, $teamId, $terminateCallback = null)
    {
        if ($removeAssignation) {
            static::where('team_id', $teamId)
                ->where('role', $roleId)
                ->get()->each(function ($teamRole) use ($terminateCallback) {
                    if ($terminateCallback) {
                        $terminateCallback($teamRole);
                    } else {
                        $teamRole->terminate();
                    }
                });
        }

        if ($removeAssignationId) {
            $teamRole = TeamRole::where('id', $removeAssignationId)->firstOrFail();
            if ($terminateCallback) {
                $terminateCallback($teamRole);
            } else {
                $teamRole->terminate();
            }
        }
    }

    public static function manageTerminateAssignationFromRequest($terminateCallback = null)
    {
        static::manageTerminateAssignation(
            request('remove_assignation', false),
            request('remove_assignation_id', null),
            request('role', null) ?? request('role_id', null),
            request('team_id', null) ?? currentTeamId(),
            $terminateCallback
        );
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

    // We're using HasSecurity plugin that handles deleting event to manage security restrictions.
    public function deletable()
    {
        return true;
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
