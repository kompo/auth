<?php

namespace Kompo\Auth\Teams\Cache;

use Condoedge\Utils\Contracts\Security\BulkResolvableTeamOwners;
use Kompo\Auth\Teams\Security\Contracts\TeamSecurityServiceInterface;
use Kompo\Auth\Teams\Security\SecurityBypassService;
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

    /**
     * Bulk-resolve team owners for a collection and seed the per-request
     * cache so the subsequent per-instance loop in
     * `BatchPermissionService::groupModelsByTeams` is fully cache-hit.
     *
     * Groups models by class. For each class that implements
     * `BulkResolvableTeamOwners`, asks the class for the whole batch in one
     * call and stores results indexed by `spl_object_hash` (the same key
     * `getTeamOwnersIdsSafe` uses). Classes that don't implement the bulk
     * contract are left alone — they fall back to the per-instance path.
     */
    public function prewarmTeamOwners(iterable $models): void
    {
        $byClass = [];
        foreach ($models as $model) {
            $class = get_class($model);
            if (!is_subclass_of($class, BulkResolvableTeamOwners::class)) {
                continue;
            }
            $byClass[$class][] = $model;
        }

        foreach ($byClass as $class => $instances) {
            // Skip classes whose instances are already fully cached.
            $missing = [];
            foreach ($instances as $instance) {
                $hash = spl_object_hash($instance);
                if (!array_key_exists($hash, static::$teamOwnersCache[$class] ?? [])) {
                    $missing[] = $instance;
                }
            }
            if (empty($missing)) {
                continue;
            }

            // Match the bypass context the per-instance path uses (see
            // TeamSecurityService::calculateTeamOwnersIds) — otherwise the
            // bulk query goes through global scopes that the per-instance
            // path skips, and the two paths would disagree.
            SecurityBypassService::enterBypassContext();
            try {
                /** @var array<int|string, array<int>> $resolved */
                $resolved = $class::bulkResolveRelatedTeamIds($missing);
            } catch (\Throwable $e) {
                // Bulk path failed — leave the cache alone so the
                // per-instance fallback in getTeamOwnersIdsSafe still runs.
                SecurityBypassService::exitBypassContext();
                continue;
            }
            SecurityBypassService::exitBypassContext();

            foreach ($missing as $instance) {
                $hash = spl_object_hash($instance);
                $key = $instance->getKey();
                static::$teamOwnersCache[$class][$hash] = $resolved[$key] ?? [];
            }
        }
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
