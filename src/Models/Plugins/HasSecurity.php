<?php

namespace Kompo\Auth\Models\Plugins;

use Kompo\Auth\Models\Plugins\Services\SecurityServiceFactory;
use Kompo\Auth\Models\Plugins\Services\SecurityBypassService;
use Kompo\Auth\Models\Plugins\Services\SecurityMetadataRegistry;
use Kompo\Auth\Models\Plugins\Services\PermissionCacheService;
use Kompo\Auth\Models\Plugins\Services\FieldProtectionService;
use Condoedge\Utils\Models\Plugins\ModelPlugin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * HasSecurity Plugin
 *
 * Provides automated security enforcement at the model level.
 * When applied to a model, this plugin restricts operations (read/write/delete) based on user permissions.
 *
 * Security Flow:
 * 1. READ: Automatically filters query results via global scope
 * 2. WRITE: Validates permissions before saving
 * 3. DELETE: Validates permissions before deleting
 * 4. Field Protection: Hides sensitive fields from unauthorized users
 *
 * The model can configure security behavior through properties:
 * - $readSecurityRestrictions (boolean)
 * - $saveSecurityRestrictions (boolean)
 * - $deleteSecurityRestrictions (boolean)
 * - $restrictByTeam (boolean)
 * - $sensibleColumns (array)
 *
 * Ownership bypass methods:
 * - scopeUserOwnedRecords - Query scope to identify records owned by the current user
 * - usersIdsAllowedToManage - Method returning user IDs with ownership-like access
 *
 * Architecture:
 * This class acts as a facade/wrapper that orchestrates specialized security services.
 * Each service handles a specific responsibility for better maintainability and testability.
 */
class HasSecurity extends ModelPlugin
{
    /**
     * Whether the request-level cleanup callback has been registered
     */
    protected static $cleanupRegistered = false;

    // Services (using dependency injection)
    protected $bypassService;
    protected $cacheService;
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
        $this->cacheService = $services['cache'];
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

        // Apply FIELD PROTECTION (with recursion prevention)
        $this->setupFieldProtectionSafe($permissionKey);

        // Setup cleanup events
        $this->setupCleanupEvents();

        // Register request-level cleanup once (here, not in setupFieldProtectionSafe,
        // so it runs regardless of lazy/batch mode)
        static::registerRequestCleanup();
    }

    /**
     * Register a single request-level cleanup callback.
     */
    protected static function registerRequestCleanup(): void
    {
        if (static::$cleanupRegistered) {
            return;
        }

        static::$cleanupRegistered = true;

        app()->terminating(function () {
            SecurityMetadataRegistry::clearAll();
            FieldProtectionService::clearTracking();
            SecurityBypassService::clearTracking();
            PermissionCacheService::clearAllCaches();
            static::$cleanupRegistered = false;
        });
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
     * Sets up field protection with simple bypass context checking
     */
    protected function setupFieldProtectionSafe(string $permissionKey): void
    {
        $meta = SecurityMetadataRegistry::for($this->modelClass);
        if ($meta['hasLazyProtectedFields'] || $meta['hasBatchProtectedFields']) {
            return;
        }

        $this->modelClass::retrieved(function ($model) use ($permissionKey) {
            $this->fieldProtectionService->handleRetrievedEvent($model, $permissionKey);
        });
    }

    /**
     * Create a new Eloquent Collection instance with auto-batch loading
     */
    public function newCollection($model, array $models = [])
    {
        $fieldProtectionService = app(SecurityServiceFactory::class)->createFieldProtectionService(get_class($model));

        if (
            !$fieldProtectionService->hasBatchProtectedFields($model)
            || $fieldProtectionService->hasLazyProtectedFields($model)
            || \Kompo\Auth\Support\SecuredModelCollection::isProcessing()
        ) {
            return new \Illuminate\Database\Eloquent\Collection($models);
        }

        return new \Kompo\Auth\Support\SecuredModelCollection($models);
    }

    /**
     * Handle getAttribute — O(1) fast path for unprotected attributes.
     * Only calls initializeServices() in the lazy resolution path.
     */
    public function getAttribute($model, $attribute, $value)
    {
        // 1. Primary key — never protected
        if ($attribute === $model->getKeyName()) {
            return $value;
        }

        // 2. Get class metadata (cached, O(1) after first call)
        $meta = SecurityMetadataRegistry::for(get_class($model));

        // 3. No protection defined for this class — skip entirely
        if (!$meta['hasProtection']) {
            return $value;
        }

        // 4. Get instance state (on the model, no string key lookup)
        $state = $model->getSecurityState();

        // 5. Already resolved as bypassed — skip
        if ($state->bypassed === true) {
            return $value;
        }

        // 6. Check blocked relationship (O(1) array lookup)
        if ($state->isRelationBlocked($attribute)) {
            return $this->getEmptyRelationResult($model, $attribute);
        }

        // 7. Not a protected relationship or column — skip (O(1) isset)
        if (!isset($meta['protectedRelationships'][$attribute])
            && !isset($meta['protectedColumns'][$attribute])) {
            return $value;
        }

        // 8. Lazy resolution (only for protected attributes on non-batch-processed models)
        return $this->resolveProtectionLazy($model, $attribute, $value, $meta, $state);
    }

    /**
     * Intercept relationship query creation from HasModelPlugins.
     * Uses SecurityMetadataRegistry for O(1) skip of non-protected relations.
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

        if ($state->bypassed === true) {
            return false;
        }

        // Not yet resolved — do lazy check
        return $this->resolveRelationBlockingLazy($model, $relation, $relationName, $meta, $state);
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
     * Lazy resolution for a protected attribute (relationship or column).
     * Called only when the attribute is confirmed protected and not yet resolved.
     * Initializes services only in this path — never in the O(1) fast path above.
     */
    protected function resolveProtectionLazy($model, string $attribute, $value, array $meta, $state)
    {
        // Global bypass context — skip all protection
        if (SecurityBypassService::isInBypassContext()) {
            return $value;
        }

        // Reentrance guard — prevent infinite loops when bypass checks access attributes
        if ($state->processing) {
            return $value;
        }

        $state->processing = true;

        try {
            // Initialize services only in this lazy path
            $this->initializeServices();

            // Check fast bypass (flag + user_id match) — set on state for future O(1) lookups
            if ($this->bypassService->isSecurityBypassRequiredFast($model, $this->teamService)) {
                $state->bypassed = true;
                return $value;
            }

            // Handle protected relationships
            if (isset($meta['protectedRelationships'][$attribute])) {
                foreach ($meta['groups'] as $group) {
                    if ($group['type'] !== 'relationships' || !in_array($attribute, $group['fields'])) {
                        continue;
                    }

                    if (!permissionMustBeAuthorized($group['key'])) {
                        continue;
                    }

                    if (!$this->fieldProtectionService->hasPermissionForProtectionKey($model, $group['key'])) {
                        $state->blockRelationships($group['fields']);
                        // Also set on model so relationLoaded() returns true
                        foreach ($group['fields'] as $rel) {
                            $model->setRelation($rel, $this->getEmptyRelationResult($model, $rel));
                        }
                        return $this->getEmptyRelationResult($model, $attribute);
                    }
                }

                return $value;
            }

            // Handle protected columns (lazy mode only)
            if (isset($meta['protectedColumns'][$attribute]) && $meta['hasLazyProtectedFields']) {
                foreach ($meta['groups'] as $group) {
                    if ($group['type'] !== 'columns' || !in_array($attribute, $group['fields'])) {
                        continue;
                    }

                    if (!permissionMustBeAuthorized($group['key'])) {
                        continue;
                    }

                    if (!$this->fieldProtectionService->hasPermissionForProtectionKey($model, $group['key'])) {
                        // Column is protected and user lacks permission — hide it
                        $this->fieldProtectionService->hideSensitiveFields($model, $group['fields']);
                        return null;
                    }
                }
            }

            return $value;
        } finally {
            $state->processing = false;
        }
    }

    /**
     * Lazy resolution for relationship blocking during interceptRelation.
     * Called only when the relation is confirmed protected and not yet resolved.
     */
    protected function resolveRelationBlockingLazy($model, $relation, string $relationName, array $meta, $state)
    {
        // Initialize services only in this lazy path
        $this->initializeServices();

        // Check fast bypass — if bypassed, set on state and allow relation
        if ($this->bypassService->isSecurityBypassRequiredFast($model, $this->teamService)) {
            $state->bypassed = true;
            return false;
        }

        // Find which group(s) this relation belongs to and check permissions
        foreach ($meta['groups'] as $group) {
            if ($group['type'] !== 'relationships' || !in_array($relationName, $group['fields'])) {
                continue;
            }

            if (!permissionMustBeAuthorized($group['key'])) {
                continue;
            }

            if (!$this->fieldProtectionService->hasPermissionForProtectionKey($model, $group['key'])) {
                // Block the relation — store on state and on model
                $state->blockRelationships($group['fields']);
                foreach ($group['fields'] as $rel) {
                    $model->setRelation($rel, $this->getEmptyRelationResult($model, $rel));
                }

                return $relation->withGlobalScope('blockedSensibleRelationship', function ($q) {
                    $q->whereRaw('1=0');
                });
            }
        }

        return false;
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
     * Setup query builder scopes
     */
    protected function setupScopes(): void
    {
        $securityBypassReasons = [
            'alreadyVerifiedAccess',
            'throughAuthorizedRelation',
            'withInherentAuthorization',
            'asSystemOperation',
            'withinCurrentTeamContext',
            'forPermissionCheck',
        ];

        foreach ($securityBypassReasons as $methodName) {
            Builder::macro($methodName, function () {
                return $this->withoutGlobalScopes(['authUserHasPermissions', 'blockedSensibleRelationship'])
                    ->addSelect(DB::raw('true as _bypassSecurity'))
                    ->selectRaw($this->model->getTable() . '.*');
            });
        }

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

        // Clean up bypass tracking
        $this->bypassService->cleanupModelBypass($model);

        // Clean up field protection tracking
        $this->fieldProtectionService->cleanupModelTracking($modelKey);

        // Clean up permission cache
        $this->cacheService->cleanupModelPermissionCache($modelKey);
    }

    /**
     * Generate a unique key for a model instance
     */
    protected function getModelKey($model): string
    {
        return get_class($model) . '_' . ($model->getKey() ?? spl_object_hash($model));
    }

    /**
     * Get permission key for this model using 3-step resolution:
     * 1. Check if model has getPermissionKey() method
     * 2. Check if model has $permissionKey property
     * 3. Fall back to class_basename()
     */
    protected function getPermissionKey(): string
    {
        if (method_exists($this->modelClass, 'getPermissionKey')) {
            return (new ($this->modelClass))->getPermissionKey();
        }

        if (property_exists($this->modelClass, 'permissionKey')) {
            return getPrivateProperty(new ($this->modelClass), 'permissionKey');
        }

        return class_basename($this->modelClass);
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
            'permission_cache_count' => PermissionCacheService::getPermissionCacheCount(),
            'batch_cache_count' => PermissionCacheService::getBatchCacheCount(),
            'memory_usage' => memory_get_usage(true),
        ];
    }

    /**
     * Force clear all field protection tracking (for debugging/testing)
     */
    public static function clearFieldProtectionTracking(): void
    {
        FieldProtectionService::clearTracking();
        SecurityBypassService::clearTracking();
        PermissionCacheService::clearAllCaches();
    }

    /**
     * Clear batch permission cache
     */
    public static function clearBatchPermissionCache(): void
    {
        PermissionCacheService::clearBatchCache();
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
