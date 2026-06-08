<?php

namespace Kompo\Auth\Models\Teams\Roles;

use Condoedge\Utils\Models\Model;
use Kompo\Auth\Contracts\Security\OptsOutOfSecurity;
use Kompo\Auth\Facades\UserModel;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Models\Traits\BelongsToManyPivotlessTrait;
use Kompo\Auth\Teams\Cache\PermissionCacheInvalidator;
use Condoedge\Utils\Models\Traits\MemoizesResults;
use Kompo\Auth\Teams\Roles\TeamRoleAssignmentGuardFacade;
use Kompo\Database\HasTranslations;

class Role extends Model implements OptsOutOfSecurity
{
    use BelongsToManyPivotlessTrait;
    use MemoizesResults;
    use HasTranslations;

    protected $casts = [
        'icon' => 'array',
        'id' => 'string',
    ];

    protected $translatable = ['name', 'description'];

    // Role rows feed the auth check itself — restricting reads here would
    // recurse infinitely.
    public function getSkippedSecurityOperations(): array
    {
        return ['read'];
    }

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

    /**
     * Every assignment of this role, regardless of status (active, suspended,
     * terminated). Drops the `validTeamRole` scope (which hides suspended/
     * terminated) and the `authUserHasPermissions` security scope so a role
     * edit can reconcile its full footprint, not just rows the actor can read.
     */
    public function teamRolesAllStatuses()
    {
        return $this->hasMany(TeamRole::class, 'role', 'id')
            ->withoutGlobalScopes(['validTeamRole', 'authUserHasPermissions']);
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

    /**
     * Default: no restriction. Apps that need to gate specific roles (e.g.
     * super-admin) override this in their own Role subclass — keep the
     * conditions aligned with TeamRoleAssignmentGuard::actorBypassesRestrictions
     * so UI filters and the save guard stay consistent.
     */
    public function scopeAvailableForUserPermissions($query, $user)
    {
        return $query->unless(TeamRoleAssignmentGuardFacade::actorBypassesRestrictions($user), fn ($q) => $q->where('id', '!=', 'super-admin'));
    }

    // ACTIONS
    public function deletable()
    {
        return true; // If authorization required we use internal system
    }

    /**
     * Strip any hierarchy component this role no longer permits from its existing
     * team_roles. The role_hierarchy is otherwise only derived at assignment time
     * (TeamRole::catchRightHiearchyBasedOnRole), so toggling roll-down/neighbours
     * off would leave stale grants behind.
     *
     * Targeted (not a blanket recompute) so per-row values like DISABLED_BELOW and
     * plain DIRECT are preserved. Idempotent: rows that don't over-grant never match.
     */
    public function clampTeamRolesHierarchyToRollFlags(): void
    {
        if (!$this->accept_roll_to_child) { // strip BELOW
            $this->teamRolesAllStatuses()->where('role_hierarchy', RoleHierarchyEnum::DIRECT_AND_BELOW)
                ->update(['role_hierarchy' => RoleHierarchyEnum::DIRECT]);                       // A -> B
            $this->teamRolesAllStatuses()->where('role_hierarchy', RoleHierarchyEnum::DIRECT_AND_BELOW_AND_NEIGHBOURS)
                ->update(['role_hierarchy' => RoleHierarchyEnum::DIRECT_AND_NEIGHBOURS]);         // E -> C
        }

        if (!$this->accept_roll_to_neighbourg) { // strip NEIGHBOURS
            $this->teamRolesAllStatuses()->where('role_hierarchy', RoleHierarchyEnum::DIRECT_AND_NEIGHBOURS)
                ->update(['role_hierarchy' => RoleHierarchyEnum::DIRECT]);                        // C -> B (and E->C from above)
            $this->teamRolesAllStatuses()->where('role_hierarchy', RoleHierarchyEnum::DIRECT_AND_BELOW_AND_NEIGHBOURS)
                ->update(['role_hierarchy' => RoleHierarchyEnum::DIRECT_AND_BELOW]);              // E -> A
        }
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
