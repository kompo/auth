<?php

namespace Kompo\Auth\Models\Plugins;

use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\Roles\PermissionException;
use Condoedge\Utils\Models\Plugins\ModelPlugin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    protected static $inBypassContextTrace = [];

    /**
     * Tracks models that were booted during bypass context and need rebooting
     */
    protected static $modelsBootedDuringBypass = [];

    /**
     * Batch permission cache for collection-level field protection
     * Format: ['user_id_permission_key_team_id' => bool]
     */

    protected static $batchPermissionCache = [];

    /**
     * Permission check cache to avoid repeated permission queries
     * Format: ['permission_key' => bool]
     */
    protected static $permissionCheckCache = [];

    /**
     * Bootstrap the security features when the model is booted.
     */
    public function onBoot()
    {
        $this->setupScopes();

        // If security is globally disabled, exit early
        if ($this->isSecurityGloballyBypassed()) {
            $this->setupBypassEvents();
            // Track that this model was booted during bypass context
            if (static::$inBypassContext) {
                static::$modelsBootedDuringBypass[$this->modelClass] = true;
            }
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
        if (config('kompo-auth.security.lazy-protected-fields') || config('kompo-auth.security.batch-protected-fields')) {
            return;
        }

        $modelClass::retrieved(function ($model) {
            $this->handleRetrievedEventSafe($model);
        });

        // Clear tracking when request ends
        if (function_exists('register_shutdown_function')) {
            register_shutdown_function(function () {
                static::$fieldProtectionInProgress = [];
                static::exitBypassContext();
                static::$permissionCheckCache = [];
                static::$modelsBootedDuringBypass = [];
                static::$batchPermissionCache = [];
            });
        }
    }

    /**
     * Create a new Eloquent Collection instance with auto-batch loading
     * This method should be called by models using HasSecurity
     *
     * @param array $models
     * @return \Kompo\Auth\Support\SecuredModelCollection
     */
    public function newCollection($model, array $models = [])
    {
        if (!config('kompo-auth.security.batch-protected-fields')) {
            return new \Illuminate\Database\Eloquent\Collection($models);
        }

        // This batch the protected fields to ensure better performance
        return new \Kompo\Auth\Support\SecuredModelCollection($models);
    }

    protected function hasLazyProtectedFields($model)
    {
        return getPrivateProperty($model, 'lazyProtectedFields') === true || config('kompo-auth.security.lazy-protected-fields');
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

        if ($this->hasLazyProtectedFields($model)) {
            return;
        }

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

    public function getAttribute($model, $attribute, $value)
    {
        if ($attribute == $model->getKeyName() || !empty(static::$fieldProtectionInProgress[$this->getModelKey($model)])) {
            return $value;
        }

        static::$fieldProtectionInProgress[$this->getModelKey($model)] = true;

        if (!$this->hasLazyProtectedFields($model)) {
            static::$fieldProtectionInProgress[$this->getModelKey($model)] = false;

            return $value;
        }

        $sensibleColumnsKey = $this->getPermissionKey() . '.sensibleColumns';

        // Apply field protection logic here
        $this->processFieldProtection($model);

        if (in_array($attribute, $this->getSensibleColumns($model)) && (static::$permissionCheckCache['user_permission_' . $sensibleColumnsKey . '_' . auth()->id()] ?? null) === false) {
            static::$fieldProtectionInProgress[$this->getModelKey($model)] = false;
            return null;
        }

        static::$fieldProtectionInProgress[$this->getModelKey($model)] = false;

        return $value;
    }

    public function getAttributes($model, $attributes)
    {
        if (!empty(static::$fieldProtectionInProgress[class_basename($model)])) {
            return $attributes;
        }

        static::$fieldProtectionInProgress[class_basename($model)] = true;

        if (!$this->hasLazyProtectedFields($model)) {
            static::$fieldProtectionInProgress[class_basename($model)] = false;

            return $attributes;
        }

        $sensibleColumnsKey = $this->getPermissionKey() . '.sensibleColumns';

        // Early exit if no sensible columns permission exists
        if (!permissionMustBeAuthorized($sensibleColumnsKey)) {
            return $attributes;
        }

        // Skip if security bypass is required (simple check)
        if ($this->isSecurityBypassRequired($model)) {
            return $attributes;
        }

        // Apply field protection logic here
        $this->processFieldProtection($model);

        static::$fieldProtectionInProgress[class_basename($model)] = false;

        return $attributes;
    }

    /**
     * Process field protection for a model
     */
    protected function processFieldProtection($model)
    {
        $sensibleColumnsKey = $this->getPermissionKey() . '.sensibleColumns';

        // Early exit if no sensible columns permission exists
        if (!permissionMustBeAuthorized($sensibleColumnsKey)) {
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
    public function isSecurityBypassRequired($model)
    {
        // If we're in bypass context, always bypass
        if ($this->isSecurityGloballyBypassed()) {
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
        static::enterBypassContext();

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
            static::exitBypassContext();
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
            static::enterBypassContext();
            $allowedUserIds = $model->usersIdsAllowedToManage();
            static::exitBypassContext();

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
        // Check if owner validation is enforced
        if ($this->shouldValidateOwnedRecords($model)) {
            return false;
        }

        if (!method_exists($model, 'scopeUserOwnedRecords') || !auth()->user()) {
            return false;
        }

        try {
            static::enterBypassContext();
            $hasByPassMethod = $model->userOwnedRecords()->where($model->getKeyName(), $model->getKey())->exists();
            static::exitBypassContext();
            return $hasByPassMethod;

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

        if (is_null($teamsIdsRelated) || empty($teamsIdsRelated)) {
            $teamsIdsRelated = ['null'];
        }

        foreach ($teamsIdsRelated as $teamId) {
            // Build proper cache key including team context
            $teamCacheKey = $teamId;
            $batchCacheKey = auth()->id() . '_' . $sensibleColumnsKey . '_' . $teamCacheKey;

            // Check batch cache first (for collection-level loading)
            if (isset(static::$batchPermissionCache[$batchCacheKey])) {
                $hasPermission = static::$batchPermissionCache[$batchCacheKey];
            } else {
                // Fall back to individual permission cache
                $permissionCacheKey = "user_permission_{$sensibleColumnsKey}_" . auth()->id() . '_' . $teamCacheKey;

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
            }
            $teamCacheKey = $teamId;
        }

        // Remove sensitive fields if permission check fails
        if (!$hasPermission) {
            $this->hideSensitiveFields($model, $sensibleColumns);
        }
    }

    /**
     * Batch load field protection permissions for a collection of models
     * This prevents N+1 queries when retrieving collections
     *
     * @param \Illuminate\Support\Collection|array $models Collection of models
     * @param int|null $userId User ID (defaults to current user)
     * @return array
     */
    public static function batchLoadFieldProtectionPermissions($models)
    {
        if (empty($models)) {
            return collect($models)->map(function ($model) {
                $sensibleColumns = $this->getSensibleColumns($model);

                return $this->hideSensitiveFields($model, $sensibleColumns);
            })->all();
        }

        $user = auth()->user();
        $userId = $user?->id;
        
        if (!$user) {
            return collect($models)->map(function ($model) {
                $sensibleColumns = $this->getSensibleColumns($model);

                return $this->hideSensitiveFields($model, $sensibleColumns);
            })->all();
        }

        // Convert to collection if array
        $modelsCollection = collect($models);

        // Get the model class from first model
        $firstModel = $modelsCollection->first();
        if (!$firstModel) {
            return [];
        }

        $modelClass = get_class($firstModel);
        $permissionKey = class_basename($modelClass);
        $sensibleColumnsKey = $permissionKey . '.sensibleColumns';

        // Check if permission exists
        if (!permissionMustBeAuthorized($sensibleColumnsKey)) {
            return $models;
        }

        if (!permissionMustBeAuthorized($sensibleColumnsKey)) {
            return $models;
        }

        // Set context for cache lookups
        static::$currentBatchPermissionKey = $sensibleColumnsKey;

        try {
            return static::batchLoadWithTeamIntersections($modelsCollection, $sensibleColumnsKey, $userId, $user);
        } finally {
            // Clean up context
            static::$currentBatchPermissionKey = null;
        }
    }

    /**
     * Advanced batch loading with team intersections for optimal performance
     */
    protected static function batchLoadWithTeamIntersections($modelsCollection, $sensibleColumnsKey, $userId, $user)
    {
        // Step 1: Group models by their team associations
        $teamModelMap = static::groupModelsByTeams($modelsCollection);
        
        // Step 2: Get teams where user has permissions (pre-filter)
        $authorizedTeams = static::getAuthorizedTeams($user, $sensibleColumnsKey);
        
        // Step 3: Calculate intersections and determine which models need processing
        $processingPlan = static::calculateProcessingPlan($teamModelMap, $authorizedTeams);
        
        // Step 4: Batch load only the necessary permission checks
        static::executeBatchPermissionChecks($processingPlan, $user, $sensibleColumnsKey, $userId);
        
        // Step 5: Apply field protection based on the results
        return static::applyFieldProtectionFromPlan($modelsCollection, $processingPlan);
    }

    /**
     * Group models by their team associations for efficient processing
     * 
     * @return array Format: [
     *   'team_123' => [model1, model2, ...],
     *   'team_456' => [model3, model4, ...],
     *   'no_team' => [model5, model6, ...]
     * ]
     */
    protected static function groupModelsByTeams($modelsCollection)
    {
        $teamModelMap = [];
        
        foreach ($modelsCollection as $model) {
            $pluginInstance = new HasSecurity($model);
            $teamIds = $pluginInstance->getTeamOwnersIdsSafe($model);
            
            // Handle models with no team
            if (empty($teamIds) || $teamIds === null) {
                $teamModelMap['no_team'][] = $model;
                continue;
            }
            
            // Handle models with multiple teams
            $teamIds = is_array($teamIds) ? $teamIds : [$teamIds];
            
            foreach ($teamIds as $teamId) {
                $teamKey = 'team_' . $teamId;
                $teamModelMap[$teamKey][] = $model;
            }
        }
        
        return $teamModelMap;
    }

    /**
     * Get teams where user already has the required permission (pre-filtering)
     */
    protected static function getAuthorizedTeams($user, $sensibleColumnsKey)
    {
        try {
            // Get all teams user has permission for this specific permission key
            $authorizedTeamIds = $user->getTeamsIdsWithPermission(
                $sensibleColumnsKey,
                PermissionTypeEnum::READ
            ) ?? [];
            
            return collect($authorizedTeamIds)->mapWithKeys(function ($teamId) {
                return ['team_' . $teamId => true];
            })->all();
            
        } catch (\Throwable $e) {
            Log::warning('Failed to get authorized teams for batch processing', [
                'permission_key' => $sensibleColumnsKey,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Calculate the optimal processing plan based on team intersections
     * 
     * @return array Format: [
     *   'authorized_models' => [model1, model2, ...],     // Models that definitely have access
     *   'unauthorized_models' => [model3, model4, ...],   // Models that definitely don't have access
     *   'needs_check' => [                                // Models that need individual permission checks
     *     'team_789' => [model5, model6, ...],
     *     'no_team' => [model7, ...]
     *   ]
     * ]
     */
    protected static function calculateProcessingPlan($teamModelMap, $authorizedTeams)
    {
        $plan = [
            'authorized_models' => [],
            'unauthorized_models' => [],
            'needs_check' => []
        ];
        
        foreach ($teamModelMap as $teamKey => $models) {
            if ($teamKey === 'no_team') {
                // Models without team need individual checking
                $plan['needs_check'][$teamKey] = $models;
                continue;
            }
            
            if (isset($authorizedTeams[$teamKey])) {
                // User has permission for this entire team - all models are authorized
                $plan['authorized_models'] = array_merge($plan['authorized_models'], $models);
            } else {
                // Need to check this team individually
                $plan['needs_check'][$teamKey] = $models;
            }
        }
        
        return $plan;
    }

    /**
     * Execute batch permission checks only for teams/models that need it
     */
    protected static function executeBatchPermissionChecks($processingPlan, $user, $sensibleColumnsKey, $userId)
    {
        foreach ($processingPlan['needs_check'] as $teamKey => $models) {
            if ($teamKey === 'no_team') {
                // Check global permission for no-team models
                $teamCacheKey = 'null';
                $batchCacheKey = $userId . '_' . $sensibleColumnsKey . '_' . $teamCacheKey;
                
                if (!isset(static::$batchPermissionCache[$batchCacheKey])) {
                    try {
                        static::$batchPermissionCache[$batchCacheKey] = $user->hasPermission(
                            $sensibleColumnsKey,
                            PermissionTypeEnum::READ,
                            null
                        ) ?? false;
                    } catch (\Throwable $e) {
                        Log::warning('Batch permission check failed for no-team models', [
                            'permission_key' => $sensibleColumnsKey,
                            'user_id' => $userId,
                            'error' => $e->getMessage()
                        ]);
                        static::$batchPermissionCache[$batchCacheKey] = false;
                    }
                }
            } else {
                // Extract team ID from team key (team_123 -> 123)
                $teamId = str_replace('team_', '', $teamKey);
                $batchCacheKey = $userId . '_' . $sensibleColumnsKey . '_' . $teamId;
                
                if (!isset(static::$batchPermissionCache[$batchCacheKey])) {
                    try {
                        static::$batchPermissionCache[$batchCacheKey] = $user->hasPermission(
                            $sensibleColumnsKey,
                            PermissionTypeEnum::READ,
                            $teamId
                        ) ?? false;
                    } catch (\Throwable $e) {
                        Log::warning('Batch permission check failed for team', [
                            'permission_key' => $sensibleColumnsKey,
                            'user_id' => $userId,
                            'team_id' => $teamId,
                            'error' => $e->getMessage()
                        ]);
                        static::$batchPermissionCache[$batchCacheKey] = false;
                    }
                }
            }
        }
    }

    /**
     * Apply field protection based on the processing plan results
     */
    protected static function applyFieldProtectionFromPlan($modelsCollection, $processingPlan)
    {
        $processedModels = [];
        
        // Process authorized models (no field hiding needed)
        foreach ($processingPlan['authorized_models'] as $model) {
            $processedModels[] = $model; // Keep as-is, they have permission
        }
        
        // Process models that need individual checks
        foreach ($processingPlan['needs_check'] as $teamKey => $models) {
            $hasPermission = static::getPermissionFromCache($teamKey, auth()->id());
            
            foreach ($models as $model) {
                if (!$hasPermission) {
                    // Hide sensitive fields
                    $pluginInstance = new HasSecurity($model);
                    $sensibleColumns = $pluginInstance->getSensibleColumns($model);
                    $pluginInstance->hideSensitiveFields($model, $sensibleColumns);
                }
                $processedModels[] = $model;
            }
        }
        
        // Process unauthorized models (hide fields)
        foreach ($processingPlan['unauthorized_models'] as $model) {
            $pluginInstance = new HasSecurity($model);
            $sensibleColumns = $pluginInstance->getSensibleColumns($model);
            $pluginInstance->hideSensitiveFields($model, $sensibleColumns);
            $processedModels[] = $model;
        }
        
        return $processedModels;
    }

    /**
     * Get permission result from cache based on team key
     */
    protected static function getPermissionFromCache($teamKey, $userId)
    {
        if ($teamKey === 'no_team') {
            $cacheKey = $userId . '_' . static::getCurrentPermissionKey() . '_null';
        } else {
            $teamId = str_replace('team_', '', $teamKey);
            $cacheKey = $userId . '_' . static::getCurrentPermissionKey() . '_' . $teamId;
        }
        
        return static::$batchPermissionCache[$cacheKey] ?? false;
    }

    /**
     * Helper to get current permission key context
     */
    protected static function getCurrentPermissionKey()
    {
        // This should be set during the batch process - we'll need to track it
        return static::$currentBatchPermissionKey ?? '';
    }

    /**
     * Track current permission key for cache lookups
     */
    protected static $currentBatchPermissionKey = null;

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
        static::enterBypassContext();

        try {
            // Strategy 1: Custom method
            if ($this->modelHasMethod($model, 'securityRelatedTeamIds')) {
                static::enterBypassContext();
                $teamIds = callPrivateMethod($model, 'securityRelatedTeamIds');
                static::exitBypassContext();

                return $teamIds;
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
     * Check if owned records should be validated (owner bypass disabled)
     *
     * @param mixed $model The model instance
     * @return bool True if owner bypass should be disabled
     */
    protected function shouldValidateOwnedRecords($model)
    {
        // Check model-specific property first
        if (property_exists($model, 'validateOwnedAsWell')) {
            return getPrivateProperty($model, 'validateOwnedAsWell');
        }

        // Fall back to global config
        return config('kompo-auth.security.default-validate-owned-as-well', false);
    }

    /**
     * Checks if bypass by user ID match applies.
     */
    protected function hasBypassByUserId($model)
    {
        // Check if owner validation is enforced
        if ($this->shouldValidateOwnedRecords($model)) {
            return false;
        }

        if ($model->getAttribute('user_id') && auth()->user() && !getPrivateProperty($model, 'disableOwnerBypass')) {
            return $model->getAttribute('user_id') === auth()->user()->id;
        }

        return false;
    }

    /**
     * Checks if bypass by explicit flag applies.
     */
    protected function hasBypassByFlag($model)
    {
        return $model->getAttribute('_bypassSecurity') == true ||
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
        $keysToRemove = array_filter(array_keys(static::$permissionCheckCache), function ($key) use ($modelKey) {
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
        static::exitBypassContext();
        static::$permissionCheckCache = [];
        static::$bypassedModels = [];
        static::$modelsBootedDuringBypass = [];
        static::$batchPermissionCache = [];
    }

    /**
     * Clear batch permission cache
     */
    public static function clearBatchPermissionCache(): void
    {
        static::$batchPermissionCache = [];
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

        // Track the call stack for debugging
        static::$inBypassContextTrace[] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    }

    /**
     * Manually exit bypass context (for special cases)
     */
    public static function exitBypassContext(): void
    {
        static::$inBypassContext = false;

        // Clear the trace
        static::$inBypassContextTrace = [];

        // Reboot models that were booted during bypass context
        foreach (static::$modelsBootedDuringBypass as $modelClass => $true) {
            // Force reboot to set up proper security scopes
            $modelClass::boot();
        }

        // Clear the tracking array
        static::$modelsBootedDuringBypass = [];
    }

    protected function isSecurityGloballyBypassed()
    {
        return globalSecurityBypass();
    }

    protected function setupReadSecurity($modelClass)
    {
        if ($this->hasReadSecurityRestrictions() && permissionMustBeAuthorized($this->getPermissionKey())) {
            $modelClass::addGlobalScope('authUserHasPermissions', function ($builder) {
                if ($this->isSecurityBypassRequired(new ($this->modelClass))) {
                    // If security is bypassed, skip the read security scope
                    return $builder;
                }

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
                static::enterBypassContext();
                $q->userOwnedRecords();
                static::exitBypassContext();
            })->when(!$hasUserOwnedRecordsScope, function ($q) {
                if (hasColumnCached($this->getModelTable(), 'user_id')) {
                    $q->where($this->getModelTable() . '.user_id', auth()->user()?->id);
                }
            });
        }
    }

    protected function applyTeamReadSecurity($builder, $hasUserOwnedRecordsScope)
    {
        $builder->where(function ($q) use ($hasUserOwnedRecordsScope) {
            // Check for new query-based security method first
            if ($this->modelHasMethod('scopeSecurityForTeamByQuery')) {
                $teamsQuery = auth()->user()?->getTeamsQueryWithPermission(
                    $this->getPermissionKey(),
                    PermissionTypeEnum::READ,
                    $this->getModelTable()
                );
                if ($teamsQuery) {
                    $q->securityForTeamByQuery($teamsQuery);
                }
            } else if ($this->modelHasMethod('scopeSecurityForTeams')) {
                // Fallback to existing method with team IDs
                $teamIds = auth()->user()?->getTeamsIdsWithPermission(
                    $this->getPermissionKey(),
                    PermissionTypeEnum::READ
                ) ?? [];
                $q->securityForTeams($teamIds);
            } else if ($teamIdCol = $this->getTeamIdColumn()) {
                $teamIds = auth()->user()?->getTeamsIdsWithPermission(
                    $this->getPermissionKey(),
                    PermissionTypeEnum::READ
                ) ?? [];
                
                $q->whereIn($this->getModelTable() . '.' . $teamIdCol, $teamIds);
            }

            if ($hasUserOwnedRecordsScope) {
                $q->orWhere(function ($sq) {
                    static::enterBypassContext();
                    $sq->userOwnedRecords();
                    static::exitBypassContext();
                });
            } else {
                if (hasColumnCached($this->getModelTable(), 'user_id')) {
                    $q->orWhere($this->getModelTable() . '.user_id', auth()->user()?->id);
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

    protected function setupScopes()
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
                return $this->withoutGlobalScopes(['authUserHasPermissions'])->addSelect(DB::raw('true as _bypassSecurity'))->selectRaw($this->model->getTable() . '.*');
            });
        }

        // Add scope to disable automatic batch loading of field protection
        Builder::macro('withoutBatchedFieldProtection', function () {
            \Kompo\Auth\Support\SecuredModelCollection::disableAutoBatching();
            return $this;
        });

        // Add scope to re-enable automatic batch loading (if it was disabled)
        Builder::macro('withBatchedFieldProtection', function () {
            \Kompo\Auth\Support\SecuredModelCollection::enableAutoBatching();
            return $this;
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
        if (!permissionMustBeAuthorized($this->getPermissionKey())) {
            return true;
        }

        if (
            !$this->individualRestrictByTeam($model) &&
            !auth()->user()?->hasPermission($this->getPermissionKey(), PermissionTypeEnum::WRITE)
        ) {
            throw new PermissionException(__('permissions-you-do-not-have-write-permissions'), $this->getPermissionKey(), PermissionTypeEnum::WRITE, []);
        }

        if (
            $this->individualRestrictByTeam($model) &&
            !auth()->user()?->hasPermission($this->getPermissionKey(), PermissionTypeEnum::WRITE, $this->getTeamOwnersIdsSafe($model))
        ) {
            throw new PermissionException(__('permissions-you-do-not-have-write-permissions'), $this->getPermissionKey(), PermissionTypeEnum::WRITE, $this->getTeamOwnersIdsSafe($model));
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

        if (hasColumnCached($this->getModelTable(), $column)) {
            return $column;
        }

        return null;
    }

    protected function getModelTable()
    {
        return (new ($this->modelClass))->getTable();
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
            'isSecurityBypassRequired',
        ];
    }
}
