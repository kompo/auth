<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Teams\CacheKeyBuilder;

/**
 * Centralized cache management for permissions
 * Handles cache invalidation, warming, and monitoring
 */
class PermissionCacheManager
{
    private const CACHE_TAG = 'permissions-v2';
    private const STATS_KEY = 'permission_cache_stats';

    /**
     * Warm cache for critical users (most active)
     */
    public function warmCriticalUserCache(): int
    {
        $criticalUsers = $this->getCriticalUsers();
        $warmed = 0;

        foreach ($criticalUsers as $userId) {
            try {
                $this->warmUserCache($userId);
                $warmed++;
            } catch (\Exception $e) {
                \Log::warning("Failed to warm cache for user {$userId}: " . $e->getMessage());
            }
        }

        return $warmed;
    }

    /**
     * Warm cache for a specific user
     */
    public function warmUserCache(int $userId): void
    {
        // Pre-load team access info
        $user = \App\Models\User::find($userId);
        if ($user) {
            $user->getAllAccessibleTeamIds();
        }
    }

    /**
     * Get list of critical users to warm cache for
     */
    private function getCriticalUsers(): array
    {
        return Cache::remember('critical_users_list', 3600, function () {
            // Get users with more team roles (more complex permissions)
            return \App\Models\User::join('team_roles', 'users.id', '=', 'team_roles.user_id')
                ->select('users.id')
                ->groupBy('users.id')
                ->orderByRaw('COUNT(team_roles.id) DESC')
                ->limit(100)
                ->pluck('id')
                ->all();
        });
    }

    /**
     * Intelligent cache invalidation based on what changed
     */
    public function invalidateByChange(string $changeType, array $affectedIds): void
    {
        switch ($changeType) {
            case 'team_role_changed':
                $this->invalidateUserPermissions($affectedIds['user_ids'] ?? []);
                break;

            case 'role_permissions_changed':
                $this->invalidateUsersWithRole($affectedIds['role_ids'] ?? []);
                break;

            case 'team_hierarchy_changed':
                $this->invalidateTeamHierarchy($affectedIds['team_ids'] ?? []);
                break;

            case 'team_created':
                $this->invalidateTeamCreated($affectedIds['team_ids'] ?? []);
                break;

            case 'permission_updated':
                $this->invalidatePermissionKey($affectedIds['permission_keys'] ?? []);
                break;

            case 'team_changed':
                $this->invalidateTeamChanged($affectedIds['team_ids'] ?? []);
                break;

            default:
                // Fallback to full cache clear
                $this->clearAllCache();
        }
    }

    /**
     * Invalidate permissions for specific users
     */
    private function invalidateUserPermissions(array $userIds): void
    {
        // Clear all user-specific cache types
        $userCacheTypes = CacheKeyBuilder::getUserSpecificCacheTypes();
        foreach ($userCacheTypes as $cacheType) {
            Cache::flushTags([$cacheType]);
        }
    }

    /**
     * Invalidate cache for users with specific roles
     */
    private function invalidateUsersWithRole(array $roleIds): void
    {
        $userIds = TeamRole::whereIn('role', $roleIds)
            ->withoutGlobalScope('authUserHasPermissions')
            ->distinct()
            ->pluck('user_id')
            ->toArray();

        $this->invalidateUserPermissions($userIds);
    }

    /**
     * Invalidate team hierarchy cache
     */
    private function invalidateTeamHierarchy(array $teamIds): void
    {
        // Clear hierarchy service cache
        $hierarchyService = app(TeamHierarchyService::class);
        foreach ($teamIds as $teamId) {
            $hierarchyService->clearCache($teamId);
        }

        // Clear team-specific cache types
        $teamCacheTypes = CacheKeyBuilder::getTeamSpecificCacheTypes();
        foreach ($teamCacheTypes as $cacheType) {
            Cache::flushTags([$cacheType]);
        }
    }

    /**
     * Invalidate cache when teams are created - affects all user accessible teams
     */
    private function invalidateTeamCreated(array $teamIds): void
    {
        // Clear hierarchy service cache for affected teams
        $hierarchyService = app(TeamHierarchyService::class);
        foreach ($teamIds as $teamId) {
            $hierarchyService->clearCache($teamId);
        }

        // Clear team-specific cache types
        $teamCacheTypes = CacheKeyBuilder::getTeamSpecificCacheTypes();
        foreach ($teamCacheTypes as $cacheType) {
            Cache::flushTags([$cacheType]);
        }
    }

    private function invalidateTeamChanged(array $teamIds): void
    {
        clearAuthStaticCache();
    }

    /**
     * Invalidate cache for specific permission keys
     */
    private function invalidatePermissionKey(array $permissionKeys): void
    {
        // This requires clearing all cache since permissions are cross-cutting
        // Changes to permission definitions affect all users and teams
        $this->clearAllCache();
    }

    /**
     * Clear all permission cache
     */
    public function clearAllCache(): void
    {
        Cache::flushTags([self::CACHE_TAG]);

        // Also clear hierarchy cache
        app(TeamHierarchyService::class)->clearCache();
    }

    /**
     * Get cache statistics for monitoring
     */
    public function getCacheStats(): array
    {
        $stats = Cache::get(self::STATS_KEY, [
            'hits' => 0,
            'misses' => 0,
            'last_clear' => null,
            'memory_usage' => 0
        ]);

        if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
            $redis = Redis::connection();
            $info = $redis->info('memory');
            $stats['memory_usage'] = $info['used_memory'] ?? 0;
        }

        return $stats;
    }

    /**
     * Record cache hit for statistics
     */
    public function recordHit(): void
    {
        $this->incrementStat('hits');
    }

    /**
     * Record cache miss for statistics
     */
    public function recordMiss(): void
    {
        $this->incrementStat('misses');
    }

    /**
     * Increment a cache statistic
     */
    private function incrementStat(string $key): void
    {
        $stats = Cache::get(self::STATS_KEY, []);
        $stats[$key] = ($stats[$key] ?? 0) + 1;
        Cache::put(self::STATS_KEY, $stats, 86400); // 24 hours
    }
}
