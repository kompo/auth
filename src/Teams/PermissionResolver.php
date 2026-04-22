<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Facades\UserModel;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Teams\Cache\AuthCacheLayer;
use Kompo\Auth\Teams\CacheKeyBuilder;
use Kompo\Auth\Teams\Contracts\PermissionResolverInterface;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;
use Kompo\Auth\Teams\Contracts\TeamRoleAccessResolverInterface;

/**
 * Centralized permission resolution service
 * Handles all permission checking logic with optimized caching and queries
 */
class PermissionResolver implements PermissionResolverInterface
{
    private TeamHierarchyInterface $hierarchyService;
    private ?AuthCacheLayer $cache;
    private ?TeamRoleAccessResolverInterface $teamRoleAccessResolver;
    private ?PermissionResolverInterface $publicApi = null;

    public function __construct(
        TeamHierarchyInterface $hierarchyService,
        ?AuthCacheLayer $cache = null,
        ?TeamRoleAccessResolverInterface $teamRoleAccessResolver = null,
    ) {
        $this->hierarchyService = $hierarchyService;
        $this->cache = $cache;
        $this->teamRoleAccessResolver = $teamRoleAccessResolver;
    }

    /**
     * Wire the public-facing API (typically the cache decorator) so internal
     * calls can route through cached variants even when the outer cache is cold.
     */
    public function setPublicApi(PermissionResolverInterface $publicApi): void
    {
        $this->publicApi = $publicApi;
    }

    /**
     * Return the public API (decorator) when wired, or $this otherwise.
     * Internal methods that have cached counterparts in the decorator should
     * route through this helper so nested calls benefit from sub-caches.
     */
    private function publicApi(): PermissionResolverInterface
    {
        return $this->publicApi ?? $this;
    }
    
    /**
     * Main permission checking method with optimized resolution
     */
    public function userHasPermission(
        int $userId, 
        string $permissionKey, 
        PermissionTypeEnum $type = PermissionTypeEnum::ALL, 
        $teamIds = null
    ): bool {
        if ($this->shouldBypassSecurity($userId)) {
            return true;
        }

        $permissionIndex = PermissionAccessIndex::fromPermissions(
            $this->getUserPermissionsOptimized($userId, $teamIds)
        );

        return $permissionIndex->allows($permissionKey, $type);
    }

    /**
     * Get teams where user has specific permission
     */
    public function getTeamsWithPermissionForUser(
        int $userId,
        string $permissionKey, 
        PermissionTypeEnum $type = PermissionTypeEnum::ALL
    ) {
        return $this->getTeamSpecificPermissions($userId, $permissionKey, $type);
    }

    /**
     * Get all teams the user has any access to
     */
    public function getAllAccessibleTeamsForUser(int $userId)
    {
        if ($this->teamRoleAccessResolver) {
            $user = UserModel::find($userId);

            return $user ? $this->teamRoleAccessResolver->accessibleTeamIds($user) : [];
        }

        $teamRoles = $this->publicApi()->getUserActiveTeamRoles($userId);
        if ($teamRoles->isEmpty()) {
            return [];
        }

        $result = collect($teamRoles->pluck('team_id')->all())
            ->filter()
            ->unique();

        $grouped = $this->groupTeamRolesByHierarchy($teamRoles);

        foreach ($grouped as $type => $group) {
            $teamIds = collect($group)->pluck('team_id')->filter()->unique()->values()->all();
            if (empty($teamIds)) {
                continue;
            }

            if ($type === 'below' || $type === 'below_and_neighbors') {
                $descendants = $this->hierarchyService
                    ->getBatchDescendantTeamIdsByRoot($teamIds)
                    ->flatten();
                $result = $result->concat($descendants);
            }

            if ($type === 'neighbors' || $type === 'below_and_neighbors') {
                $siblings = $this->hierarchyService->getBatchSiblingTeamIds($teamIds);
                $result = $result->concat($siblings);
            }
        }

        return $result->unique()->values()->all();
    }

    /**
     * Group team roles by their hierarchy access patterns for batched processing.
     */
    private function groupTeamRolesByHierarchy(Collection $teamRoles): array
    {
        $grouped = [
            'direct' => [],
            'below' => [],
            'neighbors' => [],
            'below_and_neighbors' => [],
        ];

        foreach ($teamRoles as $teamRole) {
            $hasBelow = $teamRole->getRoleHierarchyAccessBelow();
            $hasNeighbors = $teamRole->getRoleHierarchyAccessNeighbors();

            if ($hasBelow && $hasNeighbors) {
                $grouped['below_and_neighbors'][] = $teamRole;
            } elseif ($hasBelow) {
                $grouped['below'][] = $teamRole;
            } elseif ($hasNeighbors) {
                $grouped['neighbors'][] = $teamRole;
            } else {
                $grouped['direct'][] = $teamRole;
            }
        }

        return array_filter($grouped, fn($group) => !empty($group));
    }
    
    /**
     * Optimized user permissions retrieval with smart caching
     */
    public function getUserPermissionsOptimized(int $userId, $teamIds = null)
    {
        return $this->resolveUserPermissions($userId, $teamIds);
    }
    
    /**
     * Core permission resolution logic
     */
    private function resolveUserPermissions(int $userId, $teamIds = null)
    {
        // Get user's active team roles with optimized loading
        $teamRoles = $this->publicApi()->getUserActiveTeamRoles($userId, $teamIds);

        if ($teamRoles->isEmpty()) {
            return [];
        }

        $permissionData = $this->preloadPermissionData($teamRoles);

        // Resolve permissions with hierarchy consideration
        return $this->buildUserPermissionSet($teamRoles, $teamIds, $permissionData);
    }
    
    /**
     * Get user's active team roles with optimized queries
     */
    public function getUserActiveTeamRoles(int $userId, $teamIds = null): Collection
    {
        $query = TeamRole::with(['roleRelation', 'team'])
            ->where('user_id', $userId)
            ->whereHas('team')
            ->withoutGlobalScope('authUserHasPermissions');

        // Apply team filtering if specified
        if ($teamIds !== null) {
            $targetTeamIds = collect(is_iterable($teamIds) ? $teamIds : [$teamIds]);
            $accessibleTeamIds = $this->publicApi()->getAccessibleTeamIds($targetTeamIds);

            $query->whereIn('team_id', $accessibleTeamIds);
        }

        return $query->get();
    }

    /**
     * Get all team IDs that could grant access to target teams through hierarchy
     */
    public function getAccessibleTeamIds(Collection $targetTeamIds): Collection
    {
        $accessibleTeams = collect();
        
        // Add target teams themselves
        $accessibleTeams = $accessibleTeams->concat($targetTeamIds)->filter();
        $targetTeamIds = $targetTeamIds->unique()->filter();

        $accessibleTeams = $accessibleTeams
            ->concat($this->hierarchyService->getBatchAncestorTeamIds($targetTeamIds->all()))
            ->concat($this->hierarchyService->getBatchSiblingTeamIds($targetTeamIds->all()))
            ->filter();

        return $accessibleTeams->unique();
    }
    
    /**
     * Preload all permission-related data in batches
     */
    private function preloadPermissionData(Collection $teamRoles): array
    {
        $roleIds = $teamRoles->pluck('role')->unique();
        $teamRoleIds = $teamRoles->pluck('id');
        $permissionData = [
            'roles' => [],
            'team_roles' => [],
        ];

        // Batch load role permissions
        if ($roleIds->isNotEmpty()) {
            $rolePermissions = DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->whereIn('permission_role.role', $roleIds)
                ->selectRaw(constructComplexPermissionKeySql('permission_role'). ', permission_role.role as role')
                ->get()
                ->groupBy('role');

            foreach ($rolePermissions as $roleId => $permissions) {
                $perms = collect($permissions)->pluck('complex_permission_key')->all();
                $permissionData['roles'][$roleId] = $perms;
                $this->cache?->put(
                    CacheKeyBuilder::rolePermissions($roleId),
                    CacheKeyBuilder::ROLE_PERMISSIONS,
                    $perms
                );
            }
        }

        // Batch load team role permissions
        if ($teamRoleIds->isNotEmpty()) {
            $teamRolePermissions = DB::table('permission_team_role')
                ->join('permissions', 'permissions.id', '=', 'permission_team_role.permission_id')
                ->whereIn('permission_team_role.team_role_id', $teamRoleIds)
                ->selectRaw(constructComplexPermissionKeySql('permission_team_role'). ', permission_team_role.team_role_id as team_role_id')
                ->get()
                ->groupBy('team_role_id');
                
            foreach ($teamRolePermissions as $teamRoleId => $permissions) {
                $perms = collect($permissions)->pluck('complex_permission_key')->all();
                $permissionData['team_roles'][$teamRoleId] = $perms;
                $this->cache?->put(
                    CacheKeyBuilder::teamRolePermissions($teamRoleId),
                    CacheKeyBuilder::TEAM_ROLE_PERMISSIONS,
                    $perms
                );
            }
        }

        return $permissionData;
    }
    
    /**
     * Build the complete permission set for a user
     */
    private function buildUserPermissionSet(Collection $teamRoles, $teamIds = null, array $permissionData = [])
    {
        $permissions = collect();

        foreach ($teamRoles as $teamRole) {
            // Get teams this role has access to
            $accessibleTeams = collect($this->publicApi()->getTeamRoleAccessibleTeams($teamRole));

            // Filter by target teams if specified
            if ($teamIds !== null) {
                $targetTeams = collect(is_iterable($teamIds) ? $teamIds : [$teamIds]);
                $accessibleTeams = $accessibleTeams->intersect($targetTeams);
            }

            if ($accessibleTeams->isEmpty()) {
                continue;
            }

            // Add role-based permissions
            $rolePermissions = $permissionData['roles'][$teamRole->role] ?? $this->publicApi()->getRolePermissions($teamRole->roleRelation);
            $permissions = $permissions->concat($rolePermissions);

            // Add direct team role permissions
            $directPermissions = $permissionData['team_roles'][$teamRole->id] ?? $this->publicApi()->getTeamRolePermissions($teamRole);
            $permissions = $permissions->concat($directPermissions);
        }
        
        return $permissions->unique()->all();
    }
    
    /**
     * Get teams accessible through a team role (considering hierarchy)
     */
    public function getTeamRoleAccessibleTeams(TeamRole $teamRole): array
    {
        $teams = collect([$teamRole->team_id]);

        if ($teamRole->getRoleHierarchyAccessBelow()) {
            $teams = $teams->concat($this->hierarchyService->getDescendantTeamIds($teamRole->team_id));
        }

        if ($teamRole->getRoleHierarchyAccessNeighbors()) {
            $teams = $teams->concat($this->hierarchyService->getSiblingTeamIds($teamRole->team_id));
        }

        return $teams->unique()->values()->all();
    }

    /**
     * Get permissions from role with efficient query
     */
    public function getRolePermissions($role): array
    {
        if (!$role) {
            return [];
        }

        return $role->permissions()->selectRaw(constructComplexPermissionKeySql('permission_role'))
            ->pluck('complex_permission_key')->all();
    }

    public function warmRolePermissions($role): void
    {
        $this->getRolePermissions($role);
    }
    
    /**
     * Get direct team role permissions
     */
    public function getTeamRolePermissions(TeamRole $teamRole): array
    {
        return $teamRole->permissions()
            ->selectRaw(constructComplexPermissionKeySql('permission_team_role'))
            ->pluck('complex_permission_key')->all();
    }

    /**
     * Get teams where user has specific permission (not global)
     */
    private function getTeamSpecificPermissions(int $userId, string $permissionKey, PermissionTypeEnum $type)
    {
        $teamsWithPermission = collect();
        $teamRoles = $this->publicApi()->getUserActiveTeamRoles($userId);
        $permissionData = $this->preloadPermissionData($teamRoles);

        foreach ($teamRoles as $teamRole) {
            if ($this->teamRoleHasPermission($teamRole, $permissionKey, $type, $permissionData)) {
                $accessibleTeams = $this->publicApi()->getTeamRoleAccessibleTeams($teamRole);
                $teamsWithPermission = $teamsWithPermission->concat($accessibleTeams);
            }
        }

        return $teamsWithPermission->unique()->values()->all();
    }
    
    /**
     * Check if team role has specific permission (optimized)
     */
    private function teamRoleHasPermission(
        TeamRole $teamRole, 
        string $permissionKey, 
        PermissionTypeEnum $type,
        array $permissionData = []
    ): bool {
        // Check role permissions first (most common case)
        if ($teamRole->roleRelation) {
            $rolePermissions = $permissionData['roles'][$teamRole->role] ?? $this->getRolePermissions($teamRole->roleRelation);

            foreach ($rolePermissions as $permission) {
                if (getPermissionKey($permission) === $permissionKey && 
                    getPermissionType($permission) !== PermissionTypeEnum::DENY &&
                    PermissionTypeEnum::hasPermission(getPermissionType($permission), $type)) {
                    return true;
                }
            }
        }
        
        // Check direct team role permissions
        $directPermissions = $permissionData['team_roles'][$teamRole->id] ?? $this->getTeamRolePermissions($teamRole);
        
        foreach ($directPermissions as $permission) {
            if (getPermissionKey($permission) === $permissionKey && 
                getPermissionType($permission) !== PermissionTypeEnum::DENY &&
                PermissionTypeEnum::hasPermission(getPermissionType($permission), $type)) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Check for explicit deny permissions
     */
    public function hasExplicitDeny(Collection $permissions, string $permissionKey): bool
    {
        return PermissionAccessIndex::fromPermissions($permissions)->denies($permissionKey);
    }
    
    /**
     * Check if user has required permission type
     */
    public function hasRequiredPermission(
        Collection $permissions, 
        string $permissionKey, 
        PermissionTypeEnum $requiredType
    ): bool {
        return PermissionAccessIndex::fromPermissions($permissions)->hasAllowed($permissionKey, $requiredType);
    }
    
    /**
     * Check if security should be bypassed
     */
    private function shouldBypassSecurity(int $userId): bool
    {
        // Check global bypass
        if (globalSecurityBypass()) {
            return true;
        }
        
        return $this->userIsSuperAdmin($userId);
    }

    public function userIsSuperAdmin(int $userId): bool
    {
        $user = UserModel::find($userId);

        return $user && $user->isSuperAdmin();
    }
    
    /**
     * Compatibility shim for callers still resolving the concrete service.
     */
    public function clearRequestCache(): void
    {
        if (app()->bound(\Kompo\Auth\Teams\Cache\AuthCacheLayer::class)) {
            app(\Kompo\Auth\Teams\Cache\AuthCacheLayer::class)->flushRequestCache();
        }
    }
    
    /**
     * Compatibility shim for callers still resolving the concrete service.
     */
    public function clearUserCache(int $userId): void
    {
        if (app()->bound(\Kompo\Auth\Teams\Contracts\PermissionResolverInterface::class)) {
            app(\Kompo\Auth\Teams\Contracts\PermissionResolverInterface::class)->clearUserCache($userId);
        }
    }
    
    /**
     * Compatibility shim for callers still resolving the concrete service.
     */
    public function clearAllCache(): void
    {
        if (app()->bound(\Kompo\Auth\Teams\Contracts\PermissionResolverInterface::class)) {
            app(\Kompo\Auth\Teams\Contracts\PermissionResolverInterface::class)->clearAllCache();
        }
    }
    
    /**
     * Get cache statistics for monitoring
     */
    public function getCacheStats(): array
    {
        if (app()->bound(\Kompo\Auth\Teams\Cache\AuthCacheLayer::class)) {
            return app(\Kompo\Auth\Teams\Cache\AuthCacheLayer::class)->stats();
        }

        return [];
    }
    
    /**
     * Batch warm cache for multiple users
     */
    public function batchWarmCache(array $userIds): void
    {
        foreach ($userIds as $userId) {
            try {
                // Pre-load basic permissions
                $this->getUserPermissionsOptimized($userId);
                
                // Pre-load accessible teams
                $this->getAllAccessibleTeamsForUser($userId);
                
            } catch (\Exception $e) {
                \Log::warning("Failed to warm cache for user {$userId}: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Get query builder for teams where user has specific permission
     * Returns a query that can be used in EXISTS, JOIN, or subquery contexts
     */
    public function getTeamsQueryWithPermissionForUser(
        int $userId,
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL,
        ?string $teamTableAlias = null
    ): \Illuminate\Database\Query\Builder {
        $teamIds = $this->normalizeIds(
            $this->publicApi()->getTeamsWithPermissionForUser($userId, $permissionKey, $type)
        );

        return $this->buildIdQuery(
            $teamTableAlias ?: $this->resolveModelTable(TeamModel::getClass()),
            $teamIds
        );
    }

    public function getUsersQueryWithPermission(
        string $permissionKey,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL,
        $teamIds = null,
        ?string $usersTableAlias = null
    ): \Illuminate\Database\Query\Builder {
        $normalizedTeamIds = $teamIds === null ? null : $this->normalizeIds($teamIds);
        $candidateUserIds = $this->getCandidateUserIdsForPermission($permissionKey, $normalizedTeamIds);

        // Slower path for complex permissions - filter candidates in PHP to leverage full permission resolution logic
        // Including the denying logic. For some cases where permission is expected to return a lot of cases, this is not the best
        $userIds = collect($candidateUserIds)
            ->filter(function ($userId) use ($permissionKey, $type, $normalizedTeamIds) {
                return $this->publicApi()->userHasPermission(
                    (int) $userId,
                    $permissionKey,
                    $type,
                    $normalizedTeamIds
                );
            })
            ->values()
            ->all();

        return $this->buildIdQuery(
            $usersTableAlias ?: $this->resolveModelTable(UserModel::getClass()),
            $userIds
        );
    }

    private function getCandidateUserIdsForPermission(string $permissionKey, ?array $teamIds = null): array
    {
        $query = DB::table('team_roles as tr')
            ->select('tr.user_id')
            ->distinct()
            ->whereNull('tr.terminated_at')
            ->whereNull('tr.suspended_at');

        $query->where(function ($permissionQuery) use ($permissionKey) {
            $permissionQuery->whereExists(function ($rolePermissionQuery) use ($permissionKey) {
                $rolePermissionQuery->select(DB::raw(1))
                    ->from('permission_role as pr')
                    ->join('permissions as p', 'pr.permission_id', '=', 'p.id')
                    ->whereColumn('pr.role', 'tr.role')
                    ->where('p.permission_key', $permissionKey);
            })->orWhereExists(function ($teamRolePermissionQuery) use ($permissionKey) {
                $teamRolePermissionQuery->select(DB::raw(1))
                    ->from('permission_team_role as ptr')
                    ->join('permissions as p', 'ptr.permission_id', '=', 'p.id')
                    ->whereColumn('ptr.team_role_id', 'tr.id')
                    ->where('p.permission_key', $permissionKey);
            });
        });

        if ($teamIds !== null) {
            $accessibleTeamIds = $this->normalizeIds(
                $this->publicApi()->getAccessibleTeamIds(collect($teamIds))
            );

            if (empty($accessibleTeamIds)) {
                return [];
            }

            $query->whereIn('tr.team_id', $accessibleTeamIds);
        }

        return $this->normalizeIds($query->pluck('tr.user_id')->all());
    }

    private function buildIdQuery(string $tableAlias, array $ids): \Illuminate\Database\Query\Builder
    {
        $query = DB::table($tableAlias)
            ->select($tableAlias . '.id');

        if (empty($ids)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($tableAlias . '.id', $ids);
    }

    private function normalizeIds($ids): array
    {
        if ($ids instanceof Collection) {
            $ids = $ids->all();
        }

        return collect(is_iterable($ids) ? $ids : [$ids])
            ->map(fn($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function resolveModelTable(string $modelClass): string
    {
        return (new $modelClass)->getTable();
    }

    /**
     * Get performance metrics for debugging
     */
    public function getPerformanceMetrics(): array
    {
        $metrics = [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];

        if (app()->bound(\Kompo\Auth\Teams\Cache\AuthCacheLayer::class)) {
            $metrics['cache_layer'] = app(\Kompo\Auth\Teams\Cache\AuthCacheLayer::class)->stats();
        }

        return $metrics;
    }
}
