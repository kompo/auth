<?php

namespace Kompo\Auth\Teams\Cache;

use Illuminate\Support\Facades\Cache;

/**
 * Per-user cache version counter.
 *
 * Embeds an incrementing integer (`v{N}`) into user-scoped cache keys so that
 * user-specific invalidations can "bump" a user's version — making the old
 * keys unreachable — instead of flushing ALL entries with a shared tag.
 *
 * This fixes the cross-user invalidation problem where Laravel's Redis tag
 * driver would flush user X's entries when user Y's TeamRole changed.
 *
 * Old keys expire naturally via TTL.
 */
class UserCacheVersion
{
    private const TTL = 2592000; // 30 days

    /**
     * Per-request cache of (userId => version) so repeated reads in the same
     * request don't hit Redis.
     *
     * In long-lived workers (Octane/Swoole/RoadRunner) this MUST be flushed
     * at request termination via flushRequestCache() — registered in
     * KompoAuthServiceProvider::registerRequestLifecycleCleanup().
     */
    private array $requestCache = [];

    public function get(int|string $userId): int
    {
        if (isset($this->requestCache[$userId])) {
            return $this->requestCache[$userId];
        }

        $key = $this->versionKey($userId);
        $version = Cache::get($key);

        if (!$version) {
            Cache::add($key, 1, self::TTL);
            $version = 1;
        }

        $this->requestCache[$userId] = (int) $version;
        return (int) $version;
    }

    public function bump(int|string $userId): int
    {
        $key = $this->versionKey($userId);

        try {
            $new = Cache::increment($key);
            if ($new === false || $new === null) {
                Cache::add($key, 2, self::TTL);
                $new = 2;
            }
        } catch (\Throwable $e) {
            Cache::put($key, 2, self::TTL);
            $new = 2;
        }

        $this->requestCache[$userId] = (int) $new;
        return (int) $new;
    }

    public function bumpMany(array $userIds): void
    {
        foreach (array_unique(array_filter($userIds)) as $userId) {
            $this->bump($userId);
        }
    }

    public function flushRequestCache(): void
    {
        $this->requestCache = [];
    }

    private function versionKey(int|string $userId): string
    {
        return "user_cache_version.{$userId}";
    }
}
