<?php

namespace Kompo\Auth\Teams\Cache;

use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionSection;
use Kompo\Auth\Models\Teams\Roles\Role;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Teams\CacheKeyBuilder;

class PermissionDefinitionCache
{
    public function __construct(private AuthCacheLayer $cache) {}

    public function permissionByKey(string $permissionKey)
    {
        return $this->cache->remember(
            "permission_{$permissionKey}",
            CacheKeyBuilder::PERMISSION_DEFINITIONS,
            fn() => Permission::where('permission_key', $permissionKey)->first() ?? false,
            (int) config('kompo-auth.cache.permission_lookup_ttl', 60)
        );
    }

    public function forgetPermissionKey(string $permissionKey): void
    {
        $this->cache->forget("permission_{$permissionKey}");
    }

    public function permissionsForSection(PermissionSection $section)
    {
        return $this->cache->remember(
            "permissions_of_section_{$section->id}",
            CacheKeyBuilder::PERMISSION_DEFINITIONS,
            fn() => $section->permissions()->get(),
            (int) config('kompo-auth.cache.permission_definition_ttl', 3600)
        );
    }

    public function forgetPermissionsForSection(int|string $sectionId): void
    {
        $this->cache->forget("permissions_of_section_{$sectionId}");
    }

    public function roles()
    {
        return $this->cache->remember(
            'roles_all',
            CacheKeyBuilder::ROLE_DEFINITIONS,
            fn() => Role::orderBy('name')->get(),
            (int) config('kompo-auth.cache.role_list_ttl', 3600)
        );
    }

    public function rolesByRelevance()
    {
        return $this->cache->remember(
            'roles_by_relevance',
            CacheKeyBuilder::ROLE_DEFINITIONS,
            fn() => Role::query()
                ->withCount('teamRoles')
                ->orderByDesc('team_roles_count')
                ->get(),
            (int) config('kompo-auth.cache.role_list_ttl', 3600)
        );
    }

    public function teamRolePermissionKeys(TeamRole $teamRole, callable $compute)
    {
        return $this->cache->remember(
            CacheKeyBuilder::teamRolePermissions($teamRole->id),
            CacheKeyBuilder::TEAM_ROLE_PERMISSIONS,
            $compute
        );
    }
}
