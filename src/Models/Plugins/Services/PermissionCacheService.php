<?php

namespace Kompo\Auth\Models\Plugins\Services;

use Kompo\Auth\Models\Teams\Permission;
use Illuminate\Support\Facades\Log;

/**
 * Handles permission caching to avoid repeated queries
 *
 * Responsibilities:
 * - Cache permission checks
 * - Cache batch permissions for collections
 * - Manage cache keys and cleanup
 */
class PermissionCacheService
{
    /**
     * Batch permission cache for collection-level field protection
     * Format: ['user_id_permission_key_team_id' => bool]
     */
    protected static $batchPermissionCache = [];

    /**
     * Permission check cache to avoid repeated permission queries
     * Format: ['permission_key' => bool]
     */
    protected static $permissionCheckCache = [];

    /**
     * Track current permission key for cache lookups
     */
    protected static $currentBatchPermissionKey = null;

    /**
     * Check if permission exists with caching
     */
    public function permissionExists(string $permissionKey): bool
    {
        $cacheKey = "permission_exists_{$permissionKey}";

        if (!isset(static::$permissionCheckCache[$cacheKey])) {
            try {
                static::$permissionCheckCache[$cacheKey] = Permission::findByKey($permissionKey) !== false;
            } catch (\Throwable $e) {
                Log::warning('Permission check failed', [
                    'permission_key' => $permissionKey,
                    'error' => $e->getMessage()
                ]);
                static::$permissionCheckCache[$cacheKey] = false;
            }
        }

        return static::$permissionCheckCache[$cacheKey];
    }

    /**
     * Get or set batch permission cache
     */
    public function getBatchPermission(string $cacheKey): ?bool
    {
        return static::$batchPermissionCache[$cacheKey] ?? null;
    }

    /**
     * Set batch permission cache
     */
    public function setBatchPermission(string $cacheKey, bool $value): void
    {
        static::$batchPermissionCache[$cacheKey] = $value;
    }

    /**
     * Get or set permission check cache
     */
    public function getPermissionCheck(string $cacheKey): ?bool
    {
        return static::$permissionCheckCache[$cacheKey] ?? null;
    }

    /**
     * Set permission check cache
     */
    public function setPermissionCheck(string $cacheKey, bool $value): void
    {
        static::$permissionCheckCache[$cacheKey] = $value;
    }

    /**
     * Get current batch permission key
     */
    public static function getCurrentBatchPermissionKey(): ?string
    {
        return static::$currentBatchPermissionKey;
    }

    /**
     * Set current batch permission key
     */
    public static function setCurrentBatchPermissionKey(?string $key): void
    {
        static::$currentBatchPermissionKey = $key;
    }

    /**
     * Build batch cache key
     */
    public function buildBatchCacheKey(int $userId, string $permissionKey, $teamId): string
    {
        $teamCacheKey = $teamId ?? 'null';
        return "{$userId}_{$permissionKey}_{$teamCacheKey}";
    }

    /**
     * Build permission cache key
     */
    public function buildPermissionCacheKey(string $permissionKey, int $userId, $teamId): string
    {
        $teamCacheKey = $teamId ?? 'null';
        return "user_permission_{$permissionKey}_{$userId}_{$teamCacheKey}";
    }

    /**
     * Clear batch permission cache
     */
    public static function clearBatchCache(): void
    {
        static::$batchPermissionCache = [];
    }

    /**
     * Clear permission check cache
     */
    public static function clearPermissionCache(): void
    {
        static::$permissionCheckCache = [];
    }

    /**
     * Clear all caches
     */
    public static function clearAllCaches(): void
    {
        static::clearBatchCache();
        static::clearPermissionCache();
        static::$currentBatchPermissionKey = null;
    }

    /**
     * Get permission cache count
     */
    public static function getPermissionCacheCount(): int
    {
        return count(static::$permissionCheckCache);
    }

    /**
     * Get batch cache count
     */
    public static function getBatchCacheCount(): int
    {
        return count(static::$batchPermissionCache);
    }

    /**
     * Clean up permission cache for a specific model
     */
    public function cleanupModelPermissionCache(string $modelKey): void
    {
        $keysToRemove = array_filter(array_keys(static::$permissionCheckCache), function ($key) use ($modelKey) {
            return str_contains($key, $modelKey);
        });

        foreach ($keysToRemove as $key) {
            unset(static::$permissionCheckCache[$key]);
        }
    }
}
