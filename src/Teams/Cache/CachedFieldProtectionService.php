<?php

namespace Kompo\Auth\Teams\Cache;

use Kompo\Auth\Teams\Security\Contracts\FieldProtectionServiceInterface;
use Kompo\Auth\Teams\Security\FieldProtectionService;

/**
 * Per-request memo over `FieldProtectionService::hasPermissionForProtectionKey`.
 * Keyed by `(user_id, permission_key, model_class, model_key)`. Flushed at
 * request end + on Login / Logout / Impersonation.
 */
class CachedFieldProtectionService implements FieldProtectionServiceInterface
{
    /**
     * Per-request cache for `hasPermissionForProtectionKey`.
     * Key format: "{user_id}|{permission_key}|{model_class}|{model_key}"
     *
     * @var array<string, bool>
     */
    protected static array $permissionCache = [];

    public function __construct(
        protected FieldProtectionService $inner,
    ) {}

    public function hasPermissionForProtectionKey($model, string $permissionKey): bool
    {
        $cacheKey = $this->buildKey($model, $permissionKey);

        if (array_key_exists($cacheKey, static::$permissionCache)) {
            return static::$permissionCache[$cacheKey];
        }

        return static::$permissionCache[$cacheKey] = $this->inner->hasPermissionForProtectionKey($model, $permissionKey);
    }

    public function hideSensitiveFields($model, array $sensibleColumns): void
    {
        $this->inner->hideSensitiveFields($model, $sensibleColumns);
    }

    public function applyRelationshipBlocking($model, array $relationships): void
    {
        $this->inner->applyRelationshipBlocking($model, $relationships);
    }

    public function cleanupModelTracking(string $modelKey): void
    {
        // Drop any of our entries that match this model key as well, so stale
        // permission answers don't survive a save/delete of the model.
        $needle = '|' . $modelKey;
        foreach (static::$permissionCache as $key => $_) {
            if (str_ends_with($key, $needle)) {
                unset(static::$permissionCache[$key]);
            }
        }

        $this->inner->cleanupModelTracking($modelKey);
    }

    /**
     * Flush the per-request cache. Called from the request-lifecycle terminator
     * and from Login/Logout/impersonation listeners.
     */
    public static function flush(): void
    {
        static::$permissionCache = [];
    }

    protected function buildKey($model, string $permissionKey): string
    {
        $userId = auth()->id() ?? 0;
        $modelKey = $model->getKey() ?? spl_object_hash($model);
        return $userId . '|' . $permissionKey . '|' . get_class($model) . '|' . $modelKey;
    }
}
