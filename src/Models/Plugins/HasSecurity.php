<?php

namespace Kompo\Auth\Models\Plugins;

use Kompo\Auth\Models\Plugins\Services\SecurityServiceFactory;
use Kompo\Auth\Models\Plugins\Services\SecurityBypassService;
use Kompo\Auth\Models\Plugins\Services\PermissionCacheService;
use Kompo\Auth\Models\Plugins\Services\FieldProtectionService;
use Condoedge\Utils\Models\Plugins\ModelPlugin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kompo\Auth\Models\Plugins\Services\BatchPermissionService;

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
            $this->setupBypassEvents();
            SecurityBypassService::trackModelBootedDuringBypass($this->modelClass);
            return;
        }

        $permissionKey = $this->getPermissionKey();

        // Apply READ security
        $this->readSecurityService->setupReadSecurity($permissionKey);

        // Apply WRITE security
        $this->writeSecurityService->setupWriteSecurity();

        // Apply DELETE security
        $this->deleteSecurityService->setupDeleteSecurity();

        // Apply FIELD PROTECTION (with recursion prevention)
        $this->setupFieldProtectionSafe($permissionKey);

        // Setup cleanup events
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
     * Sets up field protection with simple bypass context checking
     */
    protected function setupFieldProtectionSafe(string $permissionKey): void
    {
        if (config('kompo-auth.security.lazy-protected-fields') || config('kompo-auth.security.batch-protected-fields')) {
            return;
        }

        $this->modelClass::retrieved(function ($model) use ($permissionKey) {
            $this->fieldProtectionService->handleRetrievedEvent($model, $permissionKey);
        });

        // Clear tracking when request ends
        if (function_exists('register_shutdown_function')) {
            register_shutdown_function(function () {
                FieldProtectionService::clearTracking();
                SecurityBypassService::exitBypassContext();
                PermissionCacheService::clearAllCaches();
            });
        }
    }

    /**
     * Create a new Eloquent Collection instance with auto-batch loading
     */
    public function newCollection($model, array $models = [])
    {
        if (!config('kompo-auth.security.batch-protected-fields')) {
            return new \Illuminate\Database\Eloquent\Collection($models);
        }

        return new \Kompo\Auth\Support\SecuredModelCollection($models);
    }

    /**
     * Handle getAttribute - delegate to FieldProtectionService
     */
    public function getAttribute($model, $attribute, $value)
    {
        $this->initializeServices();

        return $this->fieldProtectionService->handleGetAttribute(
            $model,
            $attribute,
            $value,
            $this->getPermissionKey()
        );
    }

    /**
     * Handle getAttributes - delegate to FieldProtectionService
     */
    public function getAttributes($model, $attributes)
    {
        $this->initializeServices();
        return $this->fieldProtectionService->handleGetAttributes(
            $model,
            $attributes,
            $this->getPermissionKey()
        );
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
     * Batch load field protection permissions - delegate to BatchPermissionService
     */
    public static function batchLoadFieldProtectionPermissions($models)
    {
        $batchService = app()->makeWith(BatchPermissionService::class, [
            'cacheService' => app(PermissionCacheService::class),
            'teamService' => null, // Will be initialized inside
            'fieldProtectionService' => null // Will be initialized inside
        ]);

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
                return $this->withoutGlobalScopes(['authUserHasPermissions'])
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
     * Get permission key for this model
     */
    protected function getPermissionKey(): string
    {
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
            'field_protection_in_progress' => FieldProtectionService::getInProgressCount(),
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
