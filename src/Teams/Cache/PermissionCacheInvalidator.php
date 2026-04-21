<?php

namespace Kompo\Auth\Teams\Cache;

use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionRole;
use Kompo\Auth\Models\Teams\PermissionTeamRole;
use Kompo\Auth\Models\Teams\Roles\Role;
use Kompo\Auth\Models\Teams\Team;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Jobs\RematerializeUserPermissions;
use Kompo\Auth\Teams\CacheKeyBuilder;
use Kompo\Auth\Teams\Contracts\PermissionResolverInterface;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;

class PermissionCacheInvalidator
{
    public function __construct(
        private AuthCacheLayer $cache,
        private TeamHierarchyInterface $hierarchy,
        private PermissionDefinitionCache $definitions,
        private UserCacheVersion $versions,
    ) {}

    public function teamRoleChanged(TeamRole $teamRole): void
    {
        $this->teamRolesChanged(
            array_filter([$teamRole->id]),
            array_filter([$teamRole->user_id])
        );
    }

    public function permissionTeamRoleChanged(PermissionTeamRole $permissionTeamRole): void
    {
        $teamRole = TeamRole::withoutGlobalScope('authUserHasPermissions')
            ->find($permissionTeamRole->team_role_id);

        $this->teamRolesChanged(
            array_filter([$permissionTeamRole->team_role_id]),
            $teamRole ? [$teamRole->user_id] : []
        );
    }

    public function permissionRoleChanged(PermissionRole $permissionRole): void
    {
        $this->rolePermissionsChanged(array_filter([$permissionRole->role]));
    }

    public function roleChanged(Role $role): void
    {
        $this->rolePermissionsChanged(array_filter([$role->id]));
        $this->cache->invalidateTag(CacheKeyBuilder::ROLE_DEFINITIONS);
    }

    public function rolePermissionsChanged(array $roleIds): void
    {
        $this->cache->invalidateTag(CacheKeyBuilder::ROLE_PERMISSIONS);
        $this->invalidateUserPermissions();
    }

    public function teamChanged(array $teamIds = []): void
    {
        $this->clearUserContext();
        $this->clearResolverRequestCache();
    }

    public function teamHierarchyChanged(array $teamIds = []): void
    {
        $this->hierarchy->clearCache();
        $this->cache->invalidateTags(array_unique(array_merge(
            CacheKeyBuilder::getTeamSpecificCacheTypes(),
            CacheKeyBuilder::getUserSpecificCacheTypes()
        )));
        $this->clearResolverRequestCache();
    }

    public function teamCreated(array $teamIds = []): void
    {
        $this->teamHierarchyChanged($teamIds);
    }

    public function userRemovedFromTeam($user, Team $team): void
    {
        $this->teamRolesChanged([], array_filter([$user?->id]));
    }

    public function permissionChanged(Permission $permission, array $permissionKeys = [], array $sectionIds = []): void
    {
        $permissionKeys = $permissionKeys ?: array_filter([
            $permission->permission_key,
            $permission->getOriginal('permission_key'),
        ]);

        $sectionIds = $sectionIds ?: array_filter([
            $permission->permission_section_id,
            $permission->getOriginal('permission_section_id'),
        ]);

        $this->permissionKeysChanged($permissionKeys, $sectionIds);
    }

    public function permissionKeysChanged(array $permissionKeys = [], array $sectionIds = []): void
    {
        foreach (array_filter(array_unique($permissionKeys)) as $permissionKey) {
            $this->definitions->forgetPermissionKey($permissionKey);
        }

        foreach (array_filter(array_unique($sectionIds)) as $sectionId) {
            $this->definitions->forgetPermissionsForSection($sectionId);
        }

        $this->cache->invalidateTags([
            CacheKeyBuilder::PERMISSION_DEFINITIONS,
            CacheKeyBuilder::USER_PERMISSIONS,
            CacheKeyBuilder::USER_TEAMS_WITH_PERMISSION,
            CacheKeyBuilder::ROLE_PERMISSIONS,
            CacheKeyBuilder::TEAM_ROLE_PERMISSIONS,
        ]);
        $this->clearResolverRequestCache();
    }

    public function clearUserContext(): void
    {
        $this->cache->invalidateTags([
            CacheKeyBuilder::CURRENT_TEAM_ROLE,
            CacheKeyBuilder::CURRENT_TEAM,
            CacheKeyBuilder::USER_SUPER_ADMIN,
        ]);
    }

    public function clearAll(): void
    {
        $this->cache->invalidateAll();
    }

    public function teamRolesChanged(array $teamRoleIds, array $userIds = []): void
    {
        // Prefer per-user version bumps so we don't wipe every other user's
        // cache entries via tag flush. The tag flush below still runs as a
        // safety net — it's cheap now that old versioned keys are unreachable.
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (!empty($userIds)) {
            $this->versions->bumpMany($userIds);
            $this->dispatchRematerializeJobs($userIds);
        }

        $this->cache->invalidateTags([
            CacheKeyBuilder::TEAM_ROLE_PERMISSIONS,
            CacheKeyBuilder::TEAM_ROLE_ACCESS,
        ]);

        $this->invalidateUserPermissions();
    }

    private function dispatchRematerializeJobs(array $userIds): void
    {
        foreach (array_unique(array_filter($userIds)) as $userId) {
            try {
                RematerializeUserPermissions::dispatch((int) $userId);
            } catch (\Throwable $e) {
                \Log::warning('Failed to dispatch RematerializeUserPermissions: ' . $e->getMessage(), [
                    'user_id' => $userId,
                ]);
            }
        }
    }

    private function invalidateUserPermissions(): void
    {
        $this->cache->invalidateTags(CacheKeyBuilder::getUserSpecificCacheTypes());
        $this->clearResolverRequestCache();
    }

    private function clearResolverRequestCache(): void
    {
        if (app()->bound(PermissionResolverInterface::class)) {
            app(PermissionResolverInterface::class)->clearRequestCache();
        }
    }
}
