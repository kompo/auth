<?php

namespace Kompo\Auth\Models\Plugins;

use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\Roles\PermissionException;
use Condoedge\Utils\Models\Plugins\ModelPlugin;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;

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
 */
class HasSecurity extends ModelPlugin
{
    /**
     * Tracks models that have bypassed security for the current request.
     */
    protected static $bypassedModels = [];

    /**
     * Tracks models currently being processed for field protection to prevent infinite loops.
     * Format: ['ModelClass_ID' => true]
     */
    protected static $fieldProtectionInProgress = [];

    /**
     * Tracks when we're in a security bypass context (like usersIdsAllowedToManage)
     * When true, all security checks are bypassed to prevent infinite loops
     */
    protected static $inBypassContext = false;

    /**
     * Cache for permission checks within the same request to avoid repeated queries.
     */
    protected static $permissionCheckCache = [];

    /**
     * Bootstrap the security features when the model is booted.
     */
    public function onBoot()
    {
        // If security is globally disabled, exit early
        if ($this->isSecurityGloballyBypassed()) {
            $this->setupBypassEvents();
            return;
        }

        $modelClass = $this->modelClass;

        // Apply READ security
        $this->setupReadSecurity($modelClass);
        
        // Apply WRITE security
        $this->setupWriteSecurity($modelClass);
        
        // Apply DELETE security
        $this->setupDeleteSecurity($modelClass);
        
        // Apply FIELD PROTECTION (with recursion prevention)
        $this->setupFieldProtectionSafe($modelClass);
        
        // Setup cleanup events
        $this->setupCleanupEvents($modelClass);
    }

    /**
     * Setup bypass events for when security is globally disabled
     */
    protected function setupBypassEvents()
    {
        $this->modelClass::saving(function ($model) {
            $this->markModelAsBypassed($model);
        });

        $this->modelClass::deleting(function ($model) {
            $this->markModelAsBypassed($model);
        });
    }

    /**
     * Sets up field protection with simple bypass context checking
     */
    protected function setupFieldProtectionSafe($modelClass)
    {
        $modelClass::retrieved(function ($model) {
            $this->handleRetrievedEventSafe($model);
        });

        // Clear tracking when request ends
        if (function_exists('register_shutdown_function')) {
            register_shutdown_function(function() {
                static::$fieldProtectionInProgress = [];
                static::$inBypassContext = false;
                static::$permissionCheckCache = [];
            });
        }
    }

    /**
     * Handles the retrieved event with simple bypass context checking
     */
    protected function handleRetrievedEventSafe($model)
    {
        // If we're in a bypass context, skip all field protection
        if (static::$inBypassContext) {
            return;
        }

        $modelKey = $this->getModelKey($model);

        // Prevent infinite loops - if this model is already being processed, skip
        if (isset(static::$fieldProtectionInProgress[$modelKey])) {
            return;
        }

        // Mark as being processed
        static::$fieldProtectionInProgress[$modelKey] = true;

        try {
            $this->processFieldProtection($model);
        } catch (\Throwable $e) {
            // Log error but don't break the application
            Log::warning('Field protection failed for model', [
                'model_class' => get_class($model),
                'model_id' => $model->getKey(),
                'error' => $e->getMessage()
            ]);
        } finally {
            // Always clean up to prevent memory leaks
            unset(static::$fieldProtectionInProgress[$modelKey]);
        }
    }

    /**
     * Process field protection for a model
     */
    protected function processFieldProtection($model)
    {
        $sensibleColumnsKey = $this->getPermissionKey() . '.sensibleColumns';
        
        // Early exit if no sensible columns permission exists
        if (!$this->permissionExists($sensibleColumnsKey)) {
            return;
        }

        // Skip if security bypass is required (simple check)
        if ($this->isSecurityBypassRequired($model)) {
            return;
        }

        $this->removeSensitiveFieldsSafe($model, $sensibleColumnsKey);
    }

    /**
     * Enhanced security bypass check with bypass context
     */
    protected function isSecurityBypassRequired($model)
    {
        // If we're in bypass context, always bypass
        if (static::$inBypassContext) {
            return true;
        }

        // Check simple flags first (no database queries)
        if ($this->hasBypassByFlag($model)) {
            return true;
        }

        if ($this->hasBypassByUserId($model)) {
            return true;
        }

        // Check custom method
        if ($this->hasBypassMethod($model)) {
            return true;
        }

        // Enter bypass context for methods that might query related models
        static::$inBypassContext = true;

        try {
            // Check allowlist (potential recursion risk)
            if ($this->hasBypassByAllowlist($model)) {
                return true;
            }

            // Check scope (potential recursion risk)
            if ($this->hasBypassByScope($model)) {
                return true;
            }

            return false;

        } finally {
            // Always exit bypass context
            static::$inBypassContext = false;
        }
    }

    /**
     * Original bypass by allowlist method (now safe due to bypass context)
     */
    protected function hasBypassByAllowlist($model)
    {
        if (!method_exists($model, 'usersIdsAllowedToManage') || !auth()->user()) {
            return false;
        }

        try {
            $allowedUserIds = $model->usersIdsAllowedToManage();
            return collect($allowedUserIds)->contains(auth()->user()->id);
        } catch (\Throwable $e) {
            Log::warning('usersIdsAllowedToManage check failed', [
                'model_class' => get_class($model),
                'model_id' => $model->getKey(),
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Original bypass by scope method (now safe due to bypass context)
     */
    protected function hasBypassByScope($model)
    {
        if (!method_exists($model, 'scopeUserOwnedRecords') || !auth()->user()) {
            return false;
        }

        try {
            return $model->userOwnedRecords()->where($model->getKeyName(), $model->getKey())->exists();
        } catch (\Throwable $e) {
            Log::warning('scopeUserOwnedRecords check failed', [
                'model_class' => get_class($model),
                'model_id' => $model->getKey(),
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if permission exists with caching to avoid repeated queries
     */
    protected function permissionExists($permissionKey)
    {
        $cacheKey = "permission_exists_{$permissionKey}";
        
        if (!isset(static::$permissionCheckCache[$cacheKey])) {
            try {
                static::$permissionCheckCache[$cacheKey] = Permission::findByKey($permissionKey) !== false;
            } catch (\Throwable $e) {
                Log::warning('Permission check failed', [
                    'permission_key' => $permissionKey,
                    'error' => $e->getMessage()
                ]);
                static::$permissionCheckCache[$cacheKey] = false;
            }
        }

        return static::$permissionCheckCache[$cacheKey];
    }

    /**
     * Removes sensitive fields with enhanced safety measures
     */
    protected function removeSensitiveFieldsSafe($model, $sensibleColumnsKey)
    {
        // Check if model has sensitive columns defined
        if (!$this->modelHasProperty($model, 'sensibleColumns')) {
            return;
        }

        $sensibleColumns = $this->getSensibleColumns($model);
        if (empty($sensibleColumns)) {
            return;
        }

        // Get team context safely (without triggering field protection)
        $teamsIdsRelated = $this->getTeamOwnersIdsSafe($model);

        // Check permission with caching
        $permissionCacheKey = "user_permission_{$sensibleColumnsKey}_" . auth()->id() . '_' . md5(serialize($teamsIdsRelated));
        
        if (!isset(static::$permissionCheckCache[$permissionCacheKey])) {
            try {
                static::$permissionCheckCache[$permissionCacheKey] = auth()->user()?->hasPermission(
                    $sensibleColumnsKey, 
                    PermissionTypeEnum::READ, 
                    $teamsIdsRelated
                ) ?? false;
            } catch (\Throwable $e) {
                Log::warning('Permission check failed for sensitive columns', [
                    'permission_key' => $sensibleColumnsKey,
                    'user_id' => auth()->id(),
                    'model_class' => get_class($model),
                    'error' => $e->getMessage()
                ]);
                // Default to hiding sensitive data if permission check fails
                static::$permissionCheckCache[$permissionCacheKey] = false;
            }
        }

        $hasPermission = static::$permissionCheckCache[$permissionCacheKey];

        // Remove sensitive fields if permission check fails
        if (!$hasPermission) {
            $this->hideSensitiveFields($model, $sensibleColumns);
        }
    }

    /**
     * Get sensitive columns safely
     */
    protected function getSensibleColumns($model)
    {
        try {
            $sensibleColumns = getPrivateProperty($model, 'sensibleColumns');
            return is_array($sensibleColumns) ? $sensibleColumns : [];
        } catch (\Throwable $e) {
            Log::warning('Failed to get sensible columns', [
                'model_class' => get_class($model),
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get team owners IDs with bypass context protection
     */
    protected function getTeamOwnersIdsSafe($model)
    {
        try {
            // Use cached result if available for this model instance
            $modelKey = $this->getModelKey($model);
            $cacheKey = "team_owners_{$modelKey}";
            
            if (isset(static::$permissionCheckCache[$cacheKey])) {
                return static::$permissionCheckCache[$cacheKey];
            }

            $result = $this->calculateTeamOwnersIds($model);
            static::$permissionCheckCache[$cacheKey] = $result;
            
            return $result;
        } catch (\Throwable $e) {
            Log::warning('Failed to get team owners IDs safely', [
                'model_class' => get_class($model),
                'model_id' => $model->getKey(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Calculate team owners IDs with bypass context
     */
    protected function calculateTeamOwnersIds($model)
    {
        // Enter bypass context for team relationship queries
        $wasInBypassContext = static::$inBypassContext;
        static::$inBypassContext = true;

        try {
            // Strategy 1: Custom method
            if ($this->modelHasMethod($model, 'securityRelatedTeamIds')) {
                return callPrivateMethod($model, 'securityRelatedTeamIds');
            }

            // Strategy 2: Direct team model check
            if ($model::class == TeamModel::getClass()) {
                return $model->getKey();
            }

            // Strategy 3: Team ID column (safest, no relations)
            $teamIdColumn = $this->getTeamIdColumn();
            if ($teamIdColumn && isset($model->{$teamIdColumn})) {
                return $model->{$teamIdColumn};
            }

            // Strategy 4: Team relationship
            if (method_exists($model, 'team')) {
                $team = $model->team()->first(['id']);
                return $team?->id;
            }

            // Strategy 5: Fallback
            return null;

        } finally {
            // Restore previous bypass context state
            static::$inBypassContext = $wasInBypassContext;
        }
    }

    /**
     * Remove the complex executeWithTimeout method since we don't need it anymore
     */

    /**
     * Hide sensitive fields from model attributes
     */
    protected function hideSensitiveFields($model, array $sensibleColumns)
    {
        try {
            $currentAttributes = $model->getRawOriginal();
            $filteredAttributes = array_diff_key($currentAttributes, array_flip($sensibleColumns));
            $model->setRawAttributes($filteredAttributes);
        } catch (\Throwable $e) {
            Log::warning('Failed to hide sensitive fields', [
                'model_class' => get_class($model),
                'model_id' => $model->getKey(),
                'sensible_columns' => $sensibleColumns,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Generate a unique key for a model instance
     */
    protected function getModelKey($model)
    {
        return get_class($model) . '_' . ($model->getKey() ?? spl_object_hash($model));
    }

    // ... [Rest of the original HasSecurity methods] ...

    /**
     * Checks if the model has a custom bypass method.
     */
    protected function hasBypassMethod($model)
    {
        if (method_exists($model, 'isSecurityBypassRequired')) {
            try {
                return $model->securityHasBeenBypassed();
            } catch (\Throwable $e) {
                Log::warning('Custom bypass method failed', [
                    'model_class' => get_class($model),
                    'error' => $e->getMessage()
                ]);
                return false;
            }
        }
        
        return false;
    }

    /**
     * Checks if bypass by user ID match applies.
     */
    protected function hasBypassByUserId($model)
    {
        if ($model->getAttribute('user_id') && auth()->user()) {
            return $model->getAttribute('user_id') === auth()->user()->id;
        }
        
        return false;
    }

    /**
     * Checks if bypass by explicit flag applies.
     */
    protected function hasBypassByFlag($model)
    {
        return $model->getAttribute('_bypassSecurity') === true || 
               (static::$bypassedModels[spl_object_hash($model)] ?? false);
    }

    /**
     * Enhanced cleanup events
     */
    protected function setupCleanupEvents($modelClass)
    {
        $modelClass::deleted(function ($model) {
            $this->cleanupModelTracking($model);
        });

        $modelClass::saved(function ($model) {
            $this->cleanupModelTracking($model);
        });
    }

    /**
     * Clean up tracking for a specific model
     */
    protected function cleanupModelTracking($model)
    {
        $modelKey = $this->getModelKey($model);
        $objectHash = spl_object_hash($model);
        
        // Clean up all tracking arrays
        unset(static::$bypassedModels[$objectHash]);
        unset(static::$fieldProtectionInProgress[$modelKey]);
        
        // Clean up permission cache for this model
        $keysToRemove = array_filter(array_keys(static::$permissionCheckCache), function($key) use ($modelKey) {
            return str_contains($key, $modelKey);
        });
        
        foreach ($keysToRemove as $key) {
            unset(static::$permissionCheckCache[$key]);
        }
    }

    /**
     * Get debug information about field protection state
     */
    public function getFieldProtectionDebugInfo(): array
    {
        return [
            'field_protection_in_progress' => count(static::$fieldProtectionInProgress),
            'in_bypass_context' => static::$inBypassContext,
            'in_progress_models' => array_keys(static::$fieldProtectionInProgress),
            'bypassed_count' => count(static::$bypassedModels),
            'permission_cache_count' => count(static::$permissionCheckCache),
            'memory_usage' => memory_get_usage(true),
        ];
    }

    /**
     * Force clear all field protection tracking (for debugging/testing)
     */
    public static function clearFieldProtectionTracking(): void
    {
        static::$fieldProtectionInProgress = [];
        static::$inBypassContext = false;
        static::$permissionCheckCache = [];
        static::$bypassedModels = [];
    }

    /**
     * Check if we're currently in bypass context (for debugging)
     */
    public static function isInBypassContext(): bool
    {
        return static::$inBypassContext;
    }

    /**
     * Manually enter bypass context (for special cases)
     */
    public static function enterBypassContext(): void
    {
        static::$inBypassContext = true;
    }

    /**
     * Manually exit bypass context (for special cases)
     */
    public static function exitBypassContext(): void
    {
        static::$inBypassContext = false;
    }

    // ... [Include all other original methods from HasSecurity] ...
    
    protected function isSecurityGloballyBypassed()
    {   
        return globalSecurityBypass();
    }

    protected function setupReadSecurity($modelClass)
    {
        if ($this->hasReadSecurityRestrictions() && Permission::findByKey($this->getPermissionKey())) {
            $modelClass::addGlobalScope('authUserHasPermissions', function ($builder) {
                $this->applyReadSecurityScope($builder);
            });
        }
    }

    protected function applyReadSecurityScope($builder)
    {
        $hasUserOwnedRecordsScope = $this->modelHasMethod('scopeUserOwnedRecords');

        if (!$this->massRestrictByTeam()) {
            $this->applyNonTeamReadSecurity($builder, $hasUserOwnedRecordsScope);
        } else {
            $this->applyTeamReadSecurity($builder, $hasUserOwnedRecordsScope);
        }
    }

    protected function applyNonTeamReadSecurity($builder, $hasUserOwnedRecordsScope)
    {
        if (!auth()->user()?->hasPermission($this->getPermissionKey(), PermissionTypeEnum::READ)) {
            $builder->when($hasUserOwnedRecordsScope, function ($q) {
                $q->userOwnedRecords();
            })->when(!$hasUserOwnedRecordsScope, function ($q) {
                if (Schema::hasColumn((new ($this->modelClass))->getTable(), 'user_id')) {
                    $q->where('user_id', auth()->user()?->id);
                }
            });
        }
    }

    protected function applyTeamReadSecurity($builder, $hasUserOwnedRecordsScope) 
    {
        $teamIds = auth()->user()?->getTeamsIdsWithPermission(
            $this->getPermissionKey(), 
            PermissionTypeEnum::READ
        );

        $builder->where(function($q) use ($teamIds, $hasUserOwnedRecordsScope) {
            if ($this->modelHasMethod('scopeSecurityForTeams')) {
                $q->securityForTeams($teamIds);
            } else if($teamIdCol = $this->getTeamIdColumn()) {
                $q->whereIn($teamIdCol, $teamIds);
            }

            if ($hasUserOwnedRecordsScope) {
                $q->orWhere(function($sq) {
                    $sq->userOwnedRecords();
                });
            } else {
                if (Schema::hasColumn((new ($this->modelClass))->getTable(), 'user_id')) {
                    $q->orWhere('user_id', auth()->user()?->id);
                }
            }
        });
    }

    protected function setupWriteSecurity($modelClass)
    {
        $modelClass::saving(function ($model) {
            $this->handleSavingEvent($model);
        });
    }

    protected function handleSavingEvent($model)
    {
        if ($this->isSecurityBypassRequired($model)) {
            $this->markModelAsBypassed($model);
            return;
        }

        if ($this->hasSaveSecurityRestrictions()) {
            $this->checkWritePermissions($model);
        }
    }

    protected function setupDeleteSecurity($modelClass)
    {
        $modelClass::deleting(function ($model) {
            $this->handleDeletingEvent($model);
        });
    }

    protected function handleDeletingEvent($model)
    {
        if ($this->isSecurityBypassRequired($model)) {
            $this->markModelAsBypassed($model);
            return;
        }

        if ($this->hasDeleteSecurityRestrictions()) {
            $this->checkWritePermissions($model);
        }
    }

    protected function markModelAsBypassed($model)
    {
        $model->offsetUnset('_bypassSecurity');
        static::$bypassedModels[spl_object_hash($model)] = true;
    }

    protected function modelHasMethod($modelOrClass, $method = null)
    {
        if (is_string($modelOrClass) && is_string($method)) {
            return method_exists($modelOrClass, $method);
        } elseif (is_object($modelOrClass) && is_string($method)) {
            return method_exists($modelOrClass, $method);
        } else {
            return method_exists($this->modelClass, $modelOrClass);
        }
    }

    protected function modelHasProperty($model, $property)
    {
        return property_exists($model, $property);
    }

    public function checkWritePermissions($model = null)
    {
        if (!Permission::findByKey($this->getPermissionKey())) {
            return true;
        }

        if (!$this->individualRestrictByTeam($model) && 
            !auth()->user()?->hasPermission($this->getPermissionKey(), PermissionTypeEnum::WRITE)) {
            throw new PermissionException(__('permissions-you-do-not-have-write-permissions'));
        }

        if ($this->individualRestrictByTeam($model) && 
            !auth()->user()?->hasPermission($this->getPermissionKey(), PermissionTypeEnum::WRITE, $this->getTeamOwnersIdsSafe($model))) {
            throw new PermissionException(__('permissions-you-do-not-have-write-permissions'));
        }
        
        return true;
    }

    protected function getPermissionKey()
    {
        return class_basename($this->modelClass);
    }

    protected function hasReadSecurityRestrictions()
    {
        if (property_exists($this->modelClass, 'readSecurityRestrictions')) {
            return getPrivateProperty(new ($this->modelClass), 'readSecurityRestrictions');
        }

        return config('kompo-auth.security.default-read-security-restrictions', true);
    }

    protected function hasDeleteSecurityRestrictions()
    {
        if (property_exists($this->modelClass, 'deleteSecurityRestrictions')) {
            return getPrivateProperty(new ($this->modelClass), 'deleteSecurityRestrictions');
        }

        return config('kompo-auth.security.default-delete-security-restrictions', true);
    }

    protected function hasSaveSecurityRestrictions()
    {
        if (property_exists($this->modelClass, 'saveSecurityRestrictions')) {
            return getPrivateProperty(new ($this->modelClass), 'saveSecurityRestrictions');
        }

        return config('kompo-auth.security.default-save-security-restrictions', true);
    }

    protected function massRestrictByTeam()
    {
        $restrictByTeam = false;

        if (property_exists($this->modelClass, 'restrictByTeam')) {
            $restrictByTeam = getPrivateProperty(new ($this->modelClass), 'restrictByTeam');
        } else {
            $restrictByTeam = config('kompo-auth.security.default-restrict-by-team', true);
        }

        if ($restrictByTeam && (!method_exists($this->modelClass, 'scopeSecurityForTeams') && !$this->getTeamIdColumn())) {
            $restrictByTeam = false;
            Log::error('The model ' . $this->modelClass . ' is not properly configured for team restrictions.');
        }

        return $restrictByTeam;
    }

    protected function individualRestrictByTeam($model)
    {
        $restrictByTeam = false;

        if (property_exists($model, 'restrictByTeam')) {
            $restrictByTeam = getPrivateProperty($model, 'restrictByTeam');
        } else {
            $restrictByTeam = config('kompo-auth.security.default-restrict-by-team', true);
        }

        if ($restrictByTeam && $this->getTeamOwnersIdsSafe($model) === false) {
            $restrictByTeam = false;
            Log::error('The model ' . $this->modelClass . ' is not properly configured for team restrictions.');
        }

        return $restrictByTeam;
    }

    protected function getTeamIdColumn()
    {
        $column = 'team_id';

        if (property_exists($this->modelClass, 'TEAM_ID_COLUMN')) {
            $column = getPrivateProperty(new ($this->modelClass), 'TEAM_ID_COLUMN');
        }

        if (Schema::hasColumn((new ($this->modelClass))->getTable(), $column)) {
            return $column;
        }

        return null;
    }

    public function systemSave($model)
    {
        $model->_bypassSecurity = true;
        $result = $model->save();
        return $result;
    }

    public function systemDelete($model)
    {
        $model->_bypassSecurity = true;
        $result = $model->delete();
        return $result;
    }

    public function managableMethods()
    {
        return [
            'checkWritePermissions',
            'systemSave',
            'systemDelete',
            'getFieldProtectionDebugInfo',
        ];
    }
}