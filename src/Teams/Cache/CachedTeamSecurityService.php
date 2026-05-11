<?php

namespace Kompo\Auth\Teams\Cache;

use Kompo\Auth\Teams\Security\Contracts\TeamSecurityServiceInterface;
use Kompo\Auth\Teams\Security\TeamSecurityService;

/**
 * Per-request cache decorator over TeamSecurityService.
 *
 * Why this exists:
 *   `getTeamOwnersIdsSafe` is called once per model in BatchPermissionService
 *   and again in FieldProtectionService, plus once per query in the read scope.
 *   The team resolution can fall through to a DB query — caching per model
 *   instance for the request lifetime avoids the N+1.
 *
 * What is cached:
 *   `getTeamOwnersIdsSafe($model)` only — keyed by `get_class($model)` +
 *   `spl_object_hash($model)`. The other interface methods are class-level
 *   decisions and either trivial or short — proxy straight through.
 *
 * Cache lifetime:
 *   One request. Flushed by `KompoAuthServiceProvider::registerRequestLifecycleCleanup`
 *   via the static `flush()` method.
 *
 * Layering:
 *   The inner TeamSecurityService is pure and stateless. This decorator
 *   implements the same interface so callers can typehint
 *   TeamSecurityServiceInterface and stay agnostic about which layer they got.
 */
class CachedTeamSecurityService implements TeamSecurityServiceInterface
{
    /**
     * Per-request cache for `getTeamOwnersIdsSafe`.
     * Format: ['App\Models\Foo' => ['spl_object_hash' => result]]
     *
     * @var array<class-string, array<string, mixed>>
     */
    protected static array $teamOwnersCache = [];

    public function __construct(
        protected TeamSecurityService $inner,
    ) {}

    public function getTeamOwnersIdsSafe($model)
    {
        $class = get_class($model);
        $hash  = spl_object_hash($model);

        if (isset(static::$teamOwnersCache[$class][$hash])
            || array_key_exists($hash, static::$teamOwnersCache[$class] ?? [])) {
            return static::$teamOwnersCache[$class][$hash];
        }

        return static::$teamOwnersCache[$class][$hash] = $this->inner->getTeamOwnersIdsSafe($model);
    }

    public function shouldValidateOwnedRecords($model): bool
    {
        return $this->inner->shouldValidateOwnedRecords($model);
    }

    public function massRestrictByTeam(string $modelClass): bool
    {
        return $this->inner->massRestrictByTeam($modelClass);
    }

    public function individualRestrictByTeam($model): bool
    {
        return $this->inner->individualRestrictByTeam($model);
    }

    /**
     * Flush the per-request cache. Called from the request-lifecycle terminator.
     */
    public static function flush(): void
    {
        static::$teamOwnersCache = [];
    }

    /**
     * Forget a single model class's entries (e.g. when a model is deleted/saved).
     */
    public static function flushFor(string $modelClass): void
    {
        unset(static::$teamOwnersCache[$modelClass]);
    }
}
