<?php

namespace Kompo\Auth\Teams\Cache;

use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Teams\CacheKeyBuilder;

class AuthCacheLayer
{
    private const ROOT_TAG = 'permissions-v2';

    private array $requestCache = [];

    public function remember(string $key, string $tag, callable $compute, ?int $ttl = null)
    {
        if (array_key_exists($key, $this->requestCache)) {
            return $this->requestCache[$key];
        }

        $tags = CacheKeyBuilder::getTagsForCacheType($tag);
        $value = Cache::rememberWithTags($tags, $key, $ttl ?? $this->ttlForTag($tag), $compute);
        $this->requestCache[$key] = $value;

        return $value;
    }

    public function rememberRequest(string $key, callable $compute)
    {
        if (!array_key_exists($key, $this->requestCache)) {
            $this->requestCache[$key] = $compute();
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

        return $value;
    }

    public function put(string $key, string $tag, $value, ?int $ttl = null): void
    {
        $this->requestCache[$key] = $value;
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
        Cache::forget($key);
    }

    public function invalidateTag(string $tag): void
    {
        Cache::flushTags([$tag]);
        $this->flushRequestCache();
    }

    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            Cache::flushTags([$tag]);
        }

        $this->flushRequestCache();
    }

    public function invalidateAll(): void
    {
        Cache::flushTags([self::ROOT_TAG]);
        $this->flushRequestCache();
    }

    public function flushRequestCache(): void
    {
        $this->requestCache = [];
    }

    public function stats(): array
    {
        return [
            'request_cache_keys' => count($this->requestCache),
            'request_cache_memory' => strlen(serialize($this->requestCache)),
            'cache_driver' => get_class(Cache::getStore()),
        ];
    }

    private function ttlForTag(string $tag): int
    {
        return match ($tag) {
            CacheKeyBuilder::TEAM_DESCENDANTS,
            CacheKeyBuilder::TEAM_DESCENDANTS_WITH_ROLE,
            CacheKeyBuilder::TEAM_IS_DESCENDANT,
            CacheKeyBuilder::TEAM_ANCESTORS,
            CacheKeyBuilder::TEAM_SIBLINGS => (int) config('kompo-auth.cache.hierarchy_ttl', 3600),

            CacheKeyBuilder::USER_SUPER_ADMIN,
            CacheKeyBuilder::IS_SUPER_ADMIN => (int) config('kompo-auth.cache.super_admin_ttl', 3600),

            CacheKeyBuilder::ROLE_DEFINITIONS => (int) config('kompo-auth.cache.role_list_ttl', 3600),
            CacheKeyBuilder::PERMISSION_DEFINITIONS => (int) config('kompo-auth.cache.permission_lookup_ttl', 60),

            default => (int) config('kompo-auth.cache.ttl', 900),
        };
    }
}
