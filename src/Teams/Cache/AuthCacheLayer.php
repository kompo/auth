<?php

namespace Kompo\Auth\Teams\Cache;

use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Teams\CacheKeyBuilder;

/**
 * Two-level cache: an in-memory per-request array on top of Laravel's persistent
 * Cache facade, with tag-scoped invalidation.
 *
 * Request-lifecycle note: `$requestCache` and `$keysByTag` live for the lifetime
 * of this singleton. In long-lived workers (Octane, Swoole, RoadRunner) the
 * container survives across requests, so `flushRequestCache()` MUST be called
 * at request termination to prevent cross-request state leaks.
 * `KompoAuthServiceProvider::registerRequestLifecycleCleanup()` registers a
 * `$this->app->terminating(...)` hook that does exactly this.
 */
class AuthCacheLayer
{
    private const ROOT_TAG = 'permissions-v2';
    private const TAG_GLOBAL = '_global';
    private const TAG_REQUEST_ONLY = '_request_only';

    private array $requestCache = [];

    /**
     * Tracks which request-cache keys were written under which tag,
     * so invalidateTag() can drop only the affected entries instead of
     * wiping the entire in-request cache.
     *
     * Shape: array<string, array<string, true>>
     *  - outer key = tag name
     *  - inner keys = request-cache keys written under that tag
     *    (value is always `true` — used as a set for O(1) dedup/lookup)
     *
     * Synthetic tags:
     *  - self::TAG_GLOBAL       : entries written via rememberGlobal()
     *  - self::TAG_REQUEST_ONLY : entries written via rememberRequest();
     *                             NEVER cleared by invalidateTag/invalidateTags
     */
    private array $keysByTag = [];

    public function remember(string $key, string $tag, callable $compute, ?int $ttl = null)
    {
        if (array_key_exists($key, $this->requestCache)) {
            return $this->requestCache[$key];
        }

        $tags = CacheKeyBuilder::getTagsForCacheType($tag);
        $value = $this->cacheRememberWithTags($tags, $key, $ttl ?? $this->ttlForTag($tag), $compute);
        $this->requestCache[$key] = $value;
        $this->trackKeyForTag($key, $tag);

        return $value;
    }

    public function rememberRequest(string $key, callable $compute)
    {
        if (!array_key_exists($key, $this->requestCache)) {
            $this->requestCache[$key] = $compute();
            $this->trackKeyForTag($key, self::TAG_REQUEST_ONLY);
        }

        return $this->requestCache[$key];
    }

    public function rememberGlobal(string $key, int $ttl, callable $compute)
    {
        if (array_key_exists($key, $this->requestCache)) {
            return $this->requestCache[$key];
        }

        $value = Cache::remember($key, $ttl, $compute);
        $this->requestCache[$key] = $value;
        $this->trackKeyForTag($key, self::TAG_GLOBAL);

        return $value;
    }

    public function put(string $key, string $tag, $value, ?int $ttl = null): void
    {
        $this->requestCache[$key] = $value;
        $this->trackKeyForTag($key, $tag);
        $tags = CacheKeyBuilder::getTagsForCacheType($tag);

        try {
            if (Cache::supportsTags()) {
                Cache::tags($tags)->put($key, $value, $ttl ?? $this->ttlForTag($tag));
                return;
            }

            Cache::put($key, $value, $ttl ?? $this->ttlForTag($tag));
        } catch (\Throwable $e) {
            \Log::warning('Auth cache put failed', [
                'key' => $key,
                'tag' => $tag,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function forget(string $key): void
    {
        unset($this->requestCache[$key]);

        foreach ($this->keysByTag as $tag => $keys) {
            unset($this->keysByTag[$tag][$key]);

            if ($this->keysByTag[$tag] === []) {
                unset($this->keysByTag[$tag]);
            }
        }

        Cache::forget($key);
    }

    public function invalidateTag(string $tag): void
    {
        $this->cacheFlushTags([$tag]);

        // Only drop request-cache entries written under this specific tag.
        // The self::TAG_REQUEST_ONLY synthetic tag is intentionally skipped here
        // so that rememberRequest() values (e.g. userHasPermission request-level
        // cache) survive unrelated model-save invalidations.
        foreach (array_keys($this->keysByTag[$tag] ?? []) as $key) {
            unset($this->requestCache[$key]);
        }

        unset($this->keysByTag[$tag]);
    }

    public function invalidateTags(array $tags): void
    {
        $this->cacheFlushTags($tags);

        foreach ($tags as $tag) {
            foreach (array_keys($this->keysByTag[$tag] ?? []) as $key) {
                unset($this->requestCache[$key]);
            }
            unset($this->keysByTag[$tag]);
        }
    }

    public function invalidateAll(): void
    {
        $this->cacheFlushTags([self::ROOT_TAG]);
        $this->flushRequestCache();
    }

    public function flushRequestCache(): void
    {
        $this->requestCache = [];
        $this->keysByTag = [];
    }

    public function stats(): array
    {
        return [
            'request_cache_keys' => count($this->requestCache),
            'request_cache_memory' => strlen(serialize($this->requestCache)),
            'cache_driver' => get_class(Cache::getStore()),
            'tags_tracked' => count($this->keysByTag),
        ];
    }

    /**
     * Record that $key was written under $tag in the request cache.
     */
    private function trackKeyForTag(string $key, string $tag): void
    {
        $this->keysByTag[$tag][$key] = true;
    }

    /**
     * Remember with tags, falling back to untagged when driver doesn't support tags.
     * Inlined to avoid dependency on Cache macros which may not be registered yet.
     */
    private function cacheRememberWithTags(array $tags, string $key, int $ttl, callable $callback)
    {
        try {
            if (Cache::supportsTags()) {
                return Cache::tags($tags)->remember($key, $ttl, $callback);
            }

            return Cache::remember($key, $ttl, $callback);
        } catch (\Throwable $e) {
            \Log::warning('Auth cache remember failed, executing callback directly', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $callback();
        }
    }

    /**
     * Flush cache entries by tag, falling back gracefully.
     */
    private function cacheFlushTags(array $tags): void
    {
        try {
            if (Cache::supportsTags()) {
                Cache::tags($tags)->flush();
            }
        } catch (\Throwable $e) {
            \Log::warning('Auth cache flush failed', [
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function ttlForTag(string $tag): int
    {
        return match ($tag) {
            CacheKeyBuilder::TEAM_DESCENDANTS,
            CacheKeyBuilder::TEAM_IS_DESCENDANT,
            CacheKeyBuilder::TEAM_ANCESTORS,
            CacheKeyBuilder::TEAM_SIBLINGS => (int) config('kompo-auth.cache.hierarchy_ttl', 3600),

            CacheKeyBuilder::USER_SUPER_ADMIN => (int) config('kompo-auth.cache.super_admin_ttl', 3600),

            CacheKeyBuilder::ROLE_DEFINITIONS => (int) config('kompo-auth.cache.role_list_ttl', 3600),
            CacheKeyBuilder::PERMISSION_DEFINITIONS => (int) config('kompo-auth.cache.permission_lookup_ttl', 60),

            default => (int) config('kompo-auth.cache.ttl', 900),
        };
    }
}
