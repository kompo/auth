<?php

namespace Kompo\Auth\Teams;

/**
 * Centralized cache key and tag builder for permissions system
 * Ensures consistent key generation and provides specific cache type tags
 */
class CacheKeyBuilder
{
    // Cache type constants - these become the tags for invalidation
    public const USER_PERMISSIONS = 'user_permissions';
    public const USER_TEAM_ACCESS = 'user_team_access';
    public const USER_TEAMS_WITH_PERMISSION = 'user_teams_with_permission';
    public const USER_ALL_ACCESSIBLE_TEAMS = 'user_all_accessible_teams';
    public const USER_ACTIVE_TEAM_ROLES = 'user_active_team_roles';
    public const USER_SUPER_ADMIN = 'user_super_admin';
    
    public const TEAM_ROLE_ACCESS = 'team_role_access';
    public const TEAM_ROLE_PERMISSIONS = 'team_role_permissions';
    public const ACCESSIBLE_TEAMS = 'accessible_teams';
    
    public const ROLE_PERMISSIONS = 'role_permissions';
    
    public const ALL_TEAM_IDS_WITH_ROLES = 'all_team_ids_with_roles';
    public const ACTIVE_TEAM_ROLES = 'active_team_roles';
    
    // Team hierarchy cache types
    public const TEAM_DESCENDANTS = 'team_descendants';
    public const TEAM_DESCENDANTS_WITH_ROLE = 'team_descendants_with_role';
    public const TEAM_IS_DESCENDANT = 'team_is_descendant';
    public const TEAM_ANCESTORS = 'team_ancestors';
    public const TEAM_SIBLINGS = 'team_siblings';
    
    // Current user context cache types
    public const CURRENT_TEAM_ROLE = 'current_team_role';
    public const CURRENT_TEAM = 'current_team';
    public const IS_SUPER_ADMIN = 'is_super_admin';
    
    /**
     * Build cache key for user permissions
     */
    public static function userPermissions(int|string $userId, $teamIds = null): string
    {
        $teamKey = $teamIds ? 
            md5(json_encode(collect($teamIds)->sort()->values())) : 
            'all';
            
        return "user_permissions.{$userId}.{$teamKey}";
    }

    /**
     * Build cache key for user team access
     */
    public static function userTeamAccess(int|string $userId, int|string $teamId, ?string $roleId = null): string
    {
        return "user_team_access.{$userId}.{$teamId}." . ($roleId ?? 'any');
    }

    /**
     * Build cache key for user teams with permission
     */
    public static function userTeamsWithPermission(int|string $userId, string $permissionKey, string $typeValue): string
    {
        return "user_teams_with_permission.{$userId}.{$permissionKey}.{$typeValue}";
    }

    /**
     * Build cache key for user all accessible teams
     */
    public static function userAllAccessibleTeams(int|string $userId): string
    {
        return "user_all_accessible_teams.{$userId}";
    }

    /**
     * Build cache key for user active team roles
     */
    public static function userActiveTeamRoles(int|string $userId, $teamIds = null): string
    {
        return "user_active_team_roles.{$userId}." . md5(serialize($teamIds));
    }

    /**
     * Build cache key for user super admin status
     */
    public static function userSuperAdmin(int|string $userId): string
    {
        return "user_super_admin.{$userId}";
    }

    /**
     * Build cache key for team role access
     */
    public static function teamRoleAccess(int|string $teamRoleId): string
    {
        return "team_role_access.{$teamRoleId}";
    }

    /**
     * Build cache key for team role permissions
     */
    public static function teamRolePermissions(int|string $teamRoleId): string
    {
        return "team_role_permissions.{$teamRoleId}";
    }

    /**
     * Build cache key for accessible teams
     */
    public static function accessibleTeams(int|string $userId, string $teamIds): string
    {
        return "accessible_teams.{$userId}.{$teamIds}";
    }

    /**
     * Build cache key for role permissions
     */
    public static function rolePermissions(string|int $roleId): string
    {
        return "role_permissions.{$roleId}";
    }

    /**
     * Build cache key for team descendants
     */
    public static function teamDescendants(int|string $teamId, ?int $maxDepth = null, ?string $search = null): string
    {
        return "descendants.{$teamId}.{$maxDepth}" . md5($search ?? '');
    }

    /**
     * Build cache key for team descendants with role
     */
    public static function teamDescendantsWithRole(int|string $teamId, string $role, ?string $search = '', ?int $limit = null): string
    {
        return "descendants_with_role.{$teamId}.{$role}." . md5($search ?? '') . ($limit ? ".{$limit}" : '');
    }

    /**
     * Build cache key for is descendant check
     */
    public static function teamIsDescendant(int|string $parentTeamId, int|string $childTeamId): string
    {
        return "is_descendant.{$parentTeamId}.{$childTeamId}";
    }

    /**
     * Build cache key for team ancestors
     */
    public static function teamAncestors(int|string $teamId): string
    {
        return "ancestors.{$teamId}";
    }

    /**
     * Build cache key for team siblings
     */
    public static function teamSiblings(int|string $teamId, ?string $search = '', ?int $limit = null): string
    {
        return "siblings.{$teamId}." . md5($search ?? '') . ($limit ? ".{$limit}" : '');
    }

    /**
     * Build cache key for current team role
     */
    public static function currentTeamRole(int|string $userId): string
    {
        return "currentTeamRole.{$userId}";
    }

    /**
     * Build cache key for current team
     */
    public static function currentTeam(int|string $userId): string
    {
        return "currentTeam.{$userId}";
    }

    /**
     * Build cache key for super admin status
     */
    public static function isSuperAdmin(int|string $userId): string
    {
        return "isSuperAdmin.{$userId}";
    }

    /**
     * Get cache tags for a specific cache type
     */
    public static function getTagsForCacheType(string $cacheType): array
    {
        return ['permissions-v2', $cacheType];
    }

    /**
     * Get all available cache types for bulk operations
     */
    public static function getAllCacheTypes(): array
    {
        return [
            self::USER_PERMISSIONS,
            self::USER_TEAM_ACCESS,
            self::USER_TEAMS_WITH_PERMISSION,
            self::USER_ALL_ACCESSIBLE_TEAMS,
            self::USER_ACTIVE_TEAM_ROLES,
            self::USER_SUPER_ADMIN,
            self::TEAM_ROLE_ACCESS,
            self::TEAM_ROLE_PERMISSIONS,
            self::ACCESSIBLE_TEAMS,
            self::ROLE_PERMISSIONS,
            self::ALL_TEAM_IDS_WITH_ROLES,
            self::ACTIVE_TEAM_ROLES,
            self::TEAM_DESCENDANTS,
            self::TEAM_DESCENDANTS_WITH_ROLE,
            self::TEAM_IS_DESCENDANT,
            self::TEAM_ANCESTORS,
            self::TEAM_SIBLINGS,
            self::CURRENT_TEAM_ROLE,
            self::CURRENT_TEAM,
            self::IS_SUPER_ADMIN,
        ];
    }

    /**
     * Get cache types that are user-specific
     */
    public static function getUserSpecificCacheTypes(): array
    {
        return [
            self::USER_PERMISSIONS,
            self::USER_TEAM_ACCESS,
            self::USER_TEAMS_WITH_PERMISSION,
            self::USER_ALL_ACCESSIBLE_TEAMS,
            self::USER_ACTIVE_TEAM_ROLES,
            self::USER_SUPER_ADMIN,
            self::ACCESSIBLE_TEAMS,
            self::CURRENT_TEAM_ROLE,
            self::CURRENT_TEAM,
            self::IS_SUPER_ADMIN,
        ];
    }

    /**
     * Get cache types that are team-specific
     */
    public static function getTeamSpecificCacheTypes(): array
    {
        return [
            self::USER_TEAM_ACCESS,
            self::TEAM_ROLE_ACCESS,
            self::TEAM_ROLE_PERMISSIONS,
            self::ACCESSIBLE_TEAMS,
        ];
    }

    /**
     * Get cache types that are role-specific
     */
    public static function getRoleSpecificCacheTypes(): array
    {
        return [
            self::ROLE_PERMISSIONS,
        ];
    }

    /**
     * Get cache types that are team hierarchy-specific
     */
    public static function getTeamHierarchyCacheTypes(): array
    {
        return [
            self::TEAM_DESCENDANTS,
            self::TEAM_DESCENDANTS_WITH_ROLE,
            self::TEAM_IS_DESCENDANT,
            self::TEAM_ANCESTORS,
            self::TEAM_SIBLINGS,
        ];
    }
}