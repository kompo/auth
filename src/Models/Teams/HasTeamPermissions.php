<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\PermissionTeamRole;
use Kompo\Auth\Models\Teams\TeamRole;

/**
 * Handles all permission logic and caching
 */
trait HasTeamPermissions
{
    public function hasAccessToTeam($teamId, $roleId = null)
    {
        return \Cache::rememberWithTags(['permissions'], 'hasAccessToTeam' . $this->id . '|' . $teamId . '|' . ($roleId ?? ''), 120, fn() =>
            $this->activeTeamRoles()
                ->when($roleId, fn($q) => $q->where('role', $roleId))
                ->get()
                ->some(fn($tr) => $tr->hasAccessToTeam($teamId))    
        );
    }

    public function getAllTeamIdsWithRolesCached($profile = 1, $search = '')
    {
        if($search) {
            return $this->getAllTeamIdsWithRoles($profile, $search);
        }

        $cacheKey = 'allTeamIdsWithRoles' . $this->id . '|' . $profile;

        return \Cache::rememberWithTags(['permissions'], $cacheKey, 180, fn() => $this->getAllTeamIdsWithRoles($profile, $search));
    }

    public function getAllTeamIdsWithRoles($profile = 1, $search = '')
    {
        return $this->activeTeamRoles()->whereHas('roleRelation', fn($q) => $q->where('profile', $profile))->get()
            ->mapWithKeys(fn($tr) => $tr->getAllHierarchyTeamsIds($search));
    }

    /**
     * Core permission checking method
     */
    public function hasPermission($permissionKey, PermissionTypeEnum $type = PermissionTypeEnum::ALL, $teamsIds = null)
    {
        $permissionsList = $teamsIds ? $this->getCurrentPermissionKeysInTeams($teamsIds) : $this->getCurrentPermissionsInAllTeams();

        return $permissionsList->first(fn($key) => $permissionKey == getPermissionKey($key) && PermissionTypeEnum::hasPermission(getPermissionType($key), $type));
    }

    public function getTeamsIdsWithPermission($permissionKey, PermissionTypeEnum $type = PermissionTypeEnum::ALL)
    {
        $cacheKey = 'teamsWithPermission' . $this->id . '|' . $permissionKey . '|' . $type->value;

        return \Cache::rememberWithTags(['permissions'], $cacheKey, 120, function () use ($permissionKey, $type) {
            $hasDenyingPermission = $this->activeTeamRoles->some(function ($teamRole) use ($permissionKey) {
                return $teamRole->denyingPermission($permissionKey);
            });

            if ($hasDenyingPermission) {
                return collect([]);
            }

            $rolesWithPermission = $this->activeTeamRoles->filter(function ($teamRole) use ($permissionKey, $type) {
                return $teamRole->hasPermission($permissionKey, $type);
            });

            $teamsWithAccess = $rolesWithPermission->reduce(function ($carry, $teamRole) {
                return $carry->concat($teamRole->getAllTeamsWithAccess());
            }, collect([]));

            return $teamsWithAccess;
        });
    }

    public function getCurrentPermissionsInAllTeams()
    {
        return \Cache::rememberWithTags(['permissions'], 'currentPermissionsInAllTeams' . $this->id, 120,
            fn() => TeamRole::getAllPermissionsKeysForMultipleRoles($this->activeTeamRoles),
        );
    }

    public function getCurrentPermissionKeysInTeams($teamsIds)
    {
        $teamsIds = collect(is_iterable($teamsIds) ? $teamsIds : [$teamsIds]);

        return \Cache::rememberWithTags(['permissions'], 'currentPermissionKeys' . $this->id . '|' . $teamsIds->implode(','), 120,
            fn() => TeamRole::getAllPermissionsKeysForMultipleRoles($this->activeTeamRoles->filter(fn($tr) => $tr->hasAccessToTeamOfMany($teamsIds))),
        );
    }

    public function givePermissionTo($permissionKey, $teamRoleId = null)
    {
        $permission = Permission::findByKey($permissionKey);
        return $this->givePermissionId($permission->id, $teamRoleId);
    }

    public function givePermissionId($permissionId, $teamRoleId = null)
    {
        $teamRoleId = $teamRoleId ?: $this->current_team_role_id;

        $permissionTeamRole = PermissionTeamRole::forPermission($permissionId)->forTeamRole($teamRoleId)->first();

        if (!$permissionTeamRole) {
            $permissionTeamRole = new PermissionTeamRole();
            $permissionTeamRole->team_role_id = $teamRoleId;
            $permissionTeamRole->permission_id = $permissionId;
            $permissionTeamRole->save();
        }

        $this->refreshRolesAndPermissionsCache();
    }
}