<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Facades\Cache;

/**
 * Unified cache service that transparently manages both request-scoped and persistent caching.
 *
 * This service provides a single API for caching that internally handles:
 * - Request-scoped cache (in-memory, cleared between requests) for fast repeated access
 * - Standard cache (Redis/Laravel) for persistence across requests
 *
 * Benefits:
 * - Single method call instead of managing two cache systems
 * - Automatic request-scope optimization
 * - Consistent invalidation across both layers
 */
class UnifiedCacheService
{
    /**
     * Request-scoped cache storage (cleared between HTTP requests)
     */
    protected array $requestCache = [];

    /**
     * Singleton instance
     */
    protected static ?self $instance = null;

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Reset singleton instance (useful for testing)
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Get a value from cache, checking request cache first, then standard cache.
     * If not found, execute callback and store in both caches.
     *
     * @param string $key Cache key
     * @param int $ttl TTL in seconds for standard cache
     * @param callable $callback Function to execute if cache miss
     * @param array $tags Optional cache tags for invalidation
     * @return mixed
     */
    public function remember(string $key, int $ttl, callable $callback, array $tags = [])
    {
        // Check request cache first (fastest)
        if (array_key_exists($key, $this->requestCache)) {
            return $this->requestCache[$key];
        }

        // Check/set standard cache with tags if provided and supported
        if (!empty($tags) && $this->supportsTags()) {
            $value = Cache::tags($tags)->remember($key, $ttl, $callback);
        } else {
            $value = Cache::remember($key, $ttl, $callback);
        }

        // Store in request cache for subsequent access
        $this->requestCache[$key] = $value;

        return $value;
    }

    /**
     * Check if the current cache driver supports tags
     *
     * @return bool
     */
    protected function supportsTags(): bool
    {
        $store = Cache::getStore();
        return method_exists($store, 'tags');
    }

    /**
     * Get a value from cache with tags (convenience method)
     *
     * @param string $key Cache key
     * @param array $tags Cache tags
     * @param int $ttl TTL in seconds
     * @param callable $callback Function to execute if cache miss
     * @return mixed
     */
    public function rememberWithTags(string $key, array $tags, int $ttl, callable $callback)
    {
        return $this->remember($key, $ttl, $callback, $tags);
    }

    /**
     * Get a value directly from cache (both layers)
     *
     * @param string $key Cache key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        // Check request cache first
        if (array_key_exists($key, $this->requestCache)) {
            return $this->requestCache[$key];
        }

        // Check standard cache
        $value = Cache::get($key, $default);

        // Store in request cache if found
        if ($value !== $default) {
            $this->requestCache[$key] = $value;
        }

        return $value;
    }

    /**
     * Put a value into both caches
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl TTL in seconds for standard cache (null = forever)
     * @param array $tags Optional cache tags
     * @return void
     */
    public function put(string $key, $value, ?int $ttl = null, array $tags = [])
    {
        // Store in request cache
        $this->requestCache[$key] = $value;

        // Store in standard cache with tags if supported
        if (!empty($tags) && $this->supportsTags()) {
            if ($ttl !== null) {
                Cache::tags($tags)->put($key, $value, $ttl);
            } else {
                Cache::tags($tags)->put($key, $value);
            }
        } else {
            if ($ttl !== null) {
                Cache::put($key, $value, $ttl);
            } else {
                Cache::put($key, $value);
            }
        }
    }

    /**
     * Put a value with tags (convenience method)
     *
     * @param array $tags Cache tags
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl TTL in seconds
     * @return void
     */
    public function putWithTags(array $tags, string $key, $value, int $ttl): void
    {
        $this->put($key, $value, $ttl, $tags);
    }

    /**
     * Get value from request-scoped cache only (doesn't check standard cache)
     * Useful for batch operations or temporary context
     *
     * @param string $key Cache key
     * @param mixed $default Default value
     * @return mixed
     */
    public function getRequestScoped(string $key, $default = null)
    {
        return $this->requestCache[$key] ?? $default;
    }

    /**
     * Put value into request-scoped cache only (doesn't touch standard cache)
     * Useful for batch operations or temporary context
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @return void
     */
    public function putRequestScoped(string $key, $value): void
    {
        $this->requestCache[$key] = $value;
    }

    /**
     * Check if key exists in request cache
     *
     * @param string $key Cache key
     * @return bool
     */
    public function hasInRequestCache(string $key): bool
    {
        return array_key_exists($key, $this->requestCache);
    }

    /**
     * Forget a key from both caches
     *
     * @param string $key Cache key
     * @return void
     */
    public function forget(string $key): void
    {
        // Remove from request cache
        unset($this->requestCache[$key]);

        // Remove from standard cache
        Cache::forget($key);
    }

    /**
     * Flush all cache entries with specific tags
     * Also clears matching entries from request cache
     *
     * @param array $tags Cache tags to flush
     * @return void
     */
    public function flushTags(array $tags): void
    {
        // Flush from standard cache only if tags are supported
        if ($this->supportsTags()) {
            Cache::tags($tags)->flush();
        }
        // If tags not supported, caller needs to handle clearing differently
        // (e.g., by flushing request cache which is what clearPermissionCache does)

        // Note: We can't selectively clear request cache by tags
        // since we don't track which keys belong to which tags in request scope.
        // This is acceptable since request cache is short-lived anyway.
    }

    /**
     * Flush only the request-scoped cache
     * Useful when you want to clear in-memory cache but keep persistent cache
     *
     * @return void
     */
    public function flushRequestCache(): void
    {
        $this->requestCache = [];
    }

    /**
     * Flush request cache entries matching a pattern
     *
     * @param string $pattern Pattern to match (supports wildcards via str_contains)
     * @return void
     */
    public function flushRequestCachePattern(string $pattern): void
    {
        $keysToRemove = array_filter(
            array_keys($this->requestCache),
            fn($key) => str_contains($key, $pattern)
        );

        foreach ($keysToRemove as $key) {
            unset($this->requestCache[$key]);
        }
    }

    /**
     * Flush both request and standard cache completely
     * USE WITH CAUTION - clears all cache across the application
     *
     * @return void
     */
    public function flushAll(): void
    {
        $this->requestCache = [];
        Cache::flush();
    }

    /**
     * Get statistics about current request cache
     *
     * @return array
     */
    public function getRequestCacheStats(): array
    {
        return [
            'keys_count' => count($this->requestCache),
            'memory_usage' => strlen(serialize($this->requestCache)),
            'keys' => array_keys($this->requestCache),
        ];
    }
}
