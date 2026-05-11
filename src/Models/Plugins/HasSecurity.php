<?php

namespace Kompo\Auth\Models\Plugins;

use Condoedge\Utils\Contracts\Security\HasOwnedRecords;
use Kompo\Auth\Teams\Cache\CachedFieldProtectionService;
use Kompo\Auth\Teams\Cache\CachedOwnedRecordsResolver;
use Kompo\Auth\Teams\Security\SecurityServiceFactory;
use Kompo\Auth\Teams\Security\SecurityBypassService;
use Kompo\Auth\Teams\Security\SecurityMetadataRegistry;
use Kompo\Auth\Teams\Security\TeamScopeIntent;
use Condoedge\Utils\Models\Plugins\ModelPlugin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * HasSecurity Plugin — automated security enforcement at the model level.
 *
 *   READ   — global scope filters query results
 *   WRITE  — permission check + dirty-protected-field check on `saving`
 *   DELETE — permission check on `deleting`
 *   FIELD PROTECTION — batch pass on the retrieved collection
 *
 * Behavior is contract-driven:
 *   `HasPermissionKey`, `ScopedToTeam`, `HasOwnedRecords`,
 *   `HasProtectedFields`, `OptsOutOfSecurity`, `EnforcesStrictPermissions`.
 *
 * No legacy properties / magic methods are read anymore — see
 * `docs/security/sources-of-truth.md`.
 */
class HasSecurity extends ModelPlugin
{
    // Services (using dependency injection)
    protected $bypassService;
    protected $teamService;
    protected $fieldProtectionService;
    protected $batchPermissionService;
    protected $readSecurityService;
    protected $writeSecurityService;
    protected $deleteSecurityService;

    /**
     * Initialize services using factory (Laravel standard DI pattern)
     */
    protected function initializeServices(): void
    {
        if ($this->bypassService) {
            return;
        }

        // Resolve factory from container
        $factory = app(SecurityServiceFactory::class);

        // Create all services for this model class
        $services = $factory->createServicesForModel($this->modelClass);

        // Assign services to properties
        $this->bypassService = $services['bypass'];
        $this->teamService = $services['team'];
        $this->fieldProtectionService = $services['fieldProtection'];
        $this->batchPermissionService = $services['batchPermission'];
        $this->readSecurityService = $services['readSecurity'];
        $this->writeSecurityService = $services['writeSecurity'];
        $this->deleteSecurityService = $services['deleteSecurity'];
    }

    /**
     * Bootstrap the security features when the model is booted.
     */
    public function onBoot()
    {
        $this->initializeServices();
        $this->setupScopes();

        // If security is globally disabled, exit early
        if ($this->bypassService->isGloballyBypassed()) {
            // The next line is complicated because when we exit the bypass context,
            // we need to ensure that any models booted during this time are properly tracked.
            // But we can't remove that saving/deleting events entirely. So we still bypass them (that's an error).
            // I think we don't need this anymore anyway (we have the bypass context everything using a service now).
            $this->setupBypassEvents();

            SecurityBypassService::trackModelBootedDuringBypass($this->modelClass);
            return;
        }

        $permissionKey = $this->getPermissionKey();

        // Apply READ security
        $this->readSecurityService->setupReadSecurity($permissionKey);

        // Apply WRITE security
        $this->writeSecurityService->setupWriteSecurity($permissionKey);

        // Apply DELETE security
        $this->deleteSecurityService->setupDeleteSecurity();

        // Field protection runs as a batch pass on collection construction
        // (see newCollection / SecuredModelCollection::autoBatch).

        $this->setupCleanupEvents();
    }

    /**
     * Setup bypass events for when security is globally disabled
     */
    protected function setupBypassEvents(): void
    {
        $this->modelClass::saving(function ($model) {
            $this->bypassService->markModelAsBypassed($model);
        });

        $this->modelClass::deleting(function ($model) {
            $this->bypassService->markModelAsBypassed($model);
        });
    }

    /**
     * Create a new Eloquent Collection. When the model class has any protected
     * fields/relations, return a `SecuredModelCollection` that batches the
     * permission check once across the whole collection.
     *
     * Field protection has a single mode — batch on retrieval. Freshly
     * constructed (un-retrieved) models are trusted to the constructing code.
     */
    public function newCollection($model, array $models = [])
    {
        $meta = SecurityMetadataRegistry::for(get_class($model));

        if (!$meta['hasProtection'] || \Kompo\Auth\Support\SecuredModelCollection::isProcessing()) {
            return new \Illuminate\Database\Eloquent\Collection($models);
        }

        return (new \Kompo\Auth\Support\SecuredModelCollection($models))->autoBatch();
    }

    /**
     * O(1) fast path for attribute access. Batch protection has already
     * decided which columns/relations are accessible by the time any
     * attribute access reaches this method.
     */
    public function getAttribute($model, $attribute, $value)
    {
        if ($attribute === $model->getKeyName()) {
            return $value;
        }

        $meta = SecurityMetadataRegistry::for(get_class($model));
        if (!$meta['hasProtection']) {
            return $value;
        }

        $state = $model->getSecurityState();
        if ($state->bypassed === true) {
            return $value;
        }

        if ($state->isRelationBlocked($attribute)) {
            return $this->getEmptyRelationResult($model, $attribute);
        }

        return $value;
    }

    /**
     * Intercept relation construction. O(1) checks against the state
     * populated by the batch pass.
     */
    public function interceptRelation($model, $relation, string $relationName)
    {
        if (SecurityBypassService::isInBypassContext()) {
            return false;
        }

        $meta = SecurityMetadataRegistry::for(get_class($model));
        if (!isset($meta['protectedRelationships'][$relationName])) {
            return false;
        }

        $state = $model->getSecurityState();
        if ($state->isRelationBlocked($relationName)) {
            return $relation->withGlobalScope('blockedSensibleRelationship', function ($q) {
                $q->whereRaw('1=0');
            });
        }

        return false;
    }

    /**
     * Intercept relationship loading via getRelationshipFromMethod.
     * Pure state lookup — no service initialization needed.
     */
    public function getRelationshipFromMethod($model, $method)
    {
        $state = $model->getSecurityState();

        if ($state->isRelationBlocked($method)) {
            return $model->$method()->whereRaw('1=0')->getResults();
        }

        return false; // false = let the parent handle it normally
    }

    /**
     * Handle getAttributes — no-op. Field protection is handled at other layers
     * (batch mode strips columns via setRawAttributes, lazy mode via getAttribute).
     */
    public function getAttributes($model, $attributes)
    {
        return $attributes;
    }

    /**
     * Get the type-appropriate empty result for a blocked relationship.
     * Returns empty collection for to-many, null for to-one.
     */
    protected function getEmptyRelationResult($model, string $attribute)
    {
        if (!method_exists($model, $attribute)) {
            return null;
        }

        $relation = $model->{$attribute}();

        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\HasMany
            || $relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany
            || $relation instanceof \Illuminate\Database\Eloquent\Relations\HasManyThrough
            || $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphMany
            || $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphToMany) {
            return $model->newCollection();
        }

        return null;
    }

    /**
     * Check if security bypass is required - delegate to SecurityBypassService
     */
    public function isSecurityBypassRequired($model)
    {
        $this->initializeServices();

        return $this->bypassService->isSecurityBypassRequired($model, $this->teamService);
    }

    /**
     * @deprecated You should use the batch service directly
     * Batch load field protection permissions - delegate to BatchPermissionService
     */
    public static function batchLoadFieldProtectionPermissions($models)
    {
        if (!isset($models[0])) {
            return [];
        }

        $factory = app(SecurityServiceFactory::class);
        $batchService = $factory->createBatchPermissionServiceForModel(get_class($models[0] ?? null));

        return $batchService->batchLoadFieldProtectionPermissions($models);
    }

    /**
     * Setup query builder scopes.
     *
     * Two flavours of bypass macro:
     *
     *   - **Full bypass** — sets `_bypassSecurity` on the hydrated model. Reads,
     *     field protection, writes, and deletes are all skipped.
     *     Use for "this is the system, trust me" paths.
     *
     *   - **Read-only bypass** — sets `_bypassReadSecurity` only. The read
     *     scope and field protection are skipped, but `save()` / `delete()`
     *     still go through their permission check.
     *     Use for "I already verified this user can read; treat the result as
     *     display data."
     */
    protected function setupScopes(): void
    {
        $fullBypassReasons = [
            'asSystemOperation',
        ];

        $readOnlyBypassReasons = [
            'alreadyVerifiedAccess',
            'throughAuthorizedRelation',
            'withInherentAuthorization',
            'withinCurrentTeamContext',
            'forPermissionCheck',
            'withReadOnlyBypass',
        ];

        foreach ($fullBypassReasons as $methodName) {
            Builder::macro($methodName, function () {
                return $this->withoutGlobalScopes(['authUserHasPermissions', 'blockedSensibleRelationship'])
                    ->addSelect(DB::raw('true as _bypassSecurity'))
                    ->selectRaw($this->model->getTable() . '.*');
            });
        }

        foreach ($readOnlyBypassReasons as $methodName) {
            Builder::macro($methodName, function () {
                return $this->withoutGlobalScopes(['authUserHasPermissions', 'blockedSensibleRelationship'])
                    ->addSelect(DB::raw('true as _bypassReadSecurity'))
                    ->selectRaw($this->model->getTable() . '.*');
            });
        }

        // Two-layer team-scope intent. The macros push an intent onto a stack
        // that `ReadSecurityService::getUserAuthorizedTeamIds` pops on next
        // scope evaluation. Cleared by the request-lifecycle terminator.
        Builder::macro('withMultiTeamAccess', function () {
            TeamScopeIntent::pushMulti();
            return $this;
        });
        Builder::macro('withCurrentTeamOnly', function () {
            TeamScopeIntent::pushCurrent();
            return $this;
        });
        Builder::macro('withoutCurrentTeamScope', function () {
            TeamScopeIntent::pushNoTeam();
            return $this;
        });

        // Add scope to disable automatic batch loading of field protection
        Builder::macro('withoutBatchedFieldProtection', function () {
            \Kompo\Auth\Support\SecuredModelCollection::disableAutoBatching();
            return $this;
        });

        // Add scope to re-enable automatic batch loading
        Builder::macro('withBatchedFieldProtection', function () {
            \Kompo\Auth\Support\SecuredModelCollection::enableAutoBatching();
            return $this;
        });
    }

    /**
     * Setup cleanup events
     */
    protected function setupCleanupEvents(): void
    {
        $this->modelClass::deleted(function ($model) {
            $this->cleanupModelTracking($model);
        });

        $this->modelClass::saved(function ($model) {
            $this->cleanupModelTracking($model);
        });
    }

    /**
     * Clean up tracking for a specific model
     */
    protected function cleanupModelTracking($model): void
    {
        $this->initializeServices();

        $modelKey = $this->getModelKey($model);

        $this->bypassService->cleanupModelBypass($model);

        $this->fieldProtectionService->cleanupModelTracking($modelKey);

        $this->flushOwnedRecordsCacheIfApplicable($model);
    }

    /**
     * Flush owned-records cache for this class on save/delete so subsequent
     * reads see fresh ownership. Coarse (per class, not per (class, user)) —
     * cheaper than tracking which user changed which record, and writes are
     * rarer than reads.
     */
    protected function flushOwnedRecordsCacheIfApplicable($model): void
    {
        $modelClass = get_class($model);

        if (is_subclass_of($modelClass, HasOwnedRecords::class)) {
            CachedOwnedRecordsResolver::flushFor($modelClass);
        }
    }

    /**
     * Generate a unique key for a model instance
     */
    protected function getModelKey($model): string
    {
        return get_class($model) . '_' . ($model->getKey() ?? spl_object_hash($model));
    }

    /**
     * Routes through the registry so contract-driven (`HasPermissionKey`) and
     * legacy (`$permissionKey` / `getPermissionKey()` / class_basename) callers
     * resolve identically. Single source of truth.
     */
    protected function getPermissionKey(): string
    {
        return SecurityMetadataRegistry::for($this->modelClass)['permissionKey'];
    }

    /**
     * Check write permissions - delegate to WriteSecurityService
     */
    public function checkWritePermissions($model = null): bool
    {
        $this->initializeServices();
        return $this->writeSecurityService->checkWritePermissions($model);
    }

    /**
     * System save (bypass security) - delegate to WriteSecurityService
     */
    public function systemSave($model): bool
    {
        $this->initializeServices();
        return $this->writeSecurityService->systemSave($model);
    }

    /**
     * System delete (bypass security) - delegate to DeleteSecurityService
     */
    public function systemDelete($model): bool
    {
        $this->initializeServices();
        return $this->deleteSecurityService->systemDelete($model);
    }

    /**
     * Get debug information about field protection state
     */
    public function getFieldProtectionDebugInfo(): array
    {
        return [
            'in_bypass_context' => SecurityBypassService::isInBypassContext(),
            'bypassed_count' => SecurityBypassService::getBypassedCount(),
            'memory_usage' => memory_get_usage(true),
        ];
    }

    /**
     * Force clear all field protection tracking (for debugging/testing)
     */
    public static function clearFieldProtectionTracking(): void
    {
        SecurityBypassService::clearTracking();
        CachedFieldProtectionService::flush();
    }

    /**
     * Clear batch permission cache (back-compat shim — the underlying cache now
     * lives in CachedFieldProtectionService and CachedPermissionResolver).
     */
    public static function clearBatchPermissionCache(): void
    {
        CachedFieldProtectionService::flush();
    }

    /**
     * Check if we're currently in bypass context (for debugging)
     */
    public static function isInBypassContext(): bool
    {
        return SecurityBypassService::isInBypassContext();
    }

    /**
     * Manually enter bypass context (for special cases)
     */
    public static function enterBypassContext(): void
    {
        SecurityBypassService::enterBypassContext();
    }

    /**
     * Manually exit bypass context (for special cases)
     */
    public static function exitBypassContext(): void
    {
        SecurityBypassService::exitBypassContext();
    }

    /**
     * Methods that can be called on model instances
     */
    public function managableMethods(): array
    {
        return [
            'checkWritePermissions',
            'systemSave',
            'systemDelete',
            'getFieldProtectionDebugInfo',
            'isSecurityBypassRequired',
        ];
    }
}
