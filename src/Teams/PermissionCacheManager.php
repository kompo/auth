<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Kompo\Auth\Teams\Cache\AuthCacheLayer;
use Kompo\Auth\Teams\Cache\PermissionCacheInvalidator;

/**
 * Centralized cache management for permissions
 * Handles cache invalidation, warming, and monitoring
 */
class PermissionCacheManager
{
    private const STATS_KEY = 'permission_cache_stats';

    public function __construct(
        private ?PermissionCacheInvalidator $invalidator = null,
        private ?AuthCacheLayer $cache = null,
    ) {}

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
        $user = \Kompo\Auth\Facades\UserModel::find($userId);
        if ($user) {
            $user->getAllAccessibleTeamIds();
        }
    }

    /**
     * Get list of critical users to warm cache for
     */
    private function getCriticalUsers(): array
    {
        return $this->cache()->rememberGlobal('critical_users_list', 3600, function () {
            $query = \Kompo\Auth\Facades\UserModel::query()
                ->join('team_roles', 'users.id', '=', 'team_roles.user_id')
                ->select('users.id')
                ->groupBy('users.id');

            if (Schema::hasColumn('users', 'last_login_at')) {
                $query->where('users.last_login_at', '>', now()->subDays(7))
                    ->orderByRaw('MAX(users.last_login_at) DESC');
            } elseif (
                Schema::hasTable('login_attempts') &&
                Schema::hasColumn('users', 'email') &&
                Schema::hasColumn('login_attempts', 'email') &&
                Schema::hasColumn('login_attempts', 'success') &&
                Schema::hasColumn('login_attempts', 'created_at')
            ) {
                $query->leftJoin('login_attempts', function ($join) {
                    $join->on('login_attempts.email', '=', 'users.email')
                        ->where('login_attempts.success', true);
                })->orderByRaw('MAX(login_attempts.created_at) DESC');
            }

            return $query->orderByRaw('COUNT(team_roles.id) DESC')
                ->limit(100)
                ->pluck('users.id')
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
                $this->invalidator()->teamRolesChanged(
                    $affectedIds['team_role_ids'] ?? [],
                    $affectedIds['user_ids'] ?? [],
                );
                break;

            case 'role_permissions_changed':
                $this->invalidator()->rolePermissionsChanged($affectedIds['role_ids'] ?? []);
                break;

            case 'team_hierarchy_changed':
                $this->invalidator()->teamHierarchyChanged($affectedIds['team_ids'] ?? []);
                break;

            case 'team_created':
                $this->invalidator()->teamCreated($affectedIds['team_ids'] ?? []);
                break;

            case 'permission_updated':
                $this->invalidator()->permissionKeysChanged(
                    $affectedIds['permission_keys'] ?? [],
                    $affectedIds['section_ids'] ?? [],
                );
                break;

            case 'team_changed':
                $this->invalidator()->teamChanged($affectedIds['team_ids'] ?? []);
                break;

            default:
                // Fallback to full cache clear
                $this->clearAllCache();
        }
    }

    /**
     * Clear all permission cache
     */
    public function clearAllCache(): void
    {
        $this->invalidator()->clearAll();
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

    private function invalidator(): PermissionCacheInvalidator
    {
        return $this->invalidator ??= app(PermissionCacheInvalidator::class);
    }

    private function cache(): AuthCacheLayer
    {
        return $this->cache ??= app(AuthCacheLayer::class);
    }
}
