<?php

namespace Kompo\Auth\Models\Plugins\Services;

use Kompo\Auth\Models\Plugins\Services\Traits\ModelHelperTrait;
use Kompo\Auth\Models\Plugins\Services\SecurityMetadataRegistry;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Illuminate\Support\Facades\Log;
use Kompo\Auth\Models\Plugins\HasSecurity;

/**
 * Handles field protection and sensitive column hiding
 *
 * Responsibilities:
 * - Process field protection on retrieved models
 * - Hide sensitive fields from unauthorized users
 * - Track field protection progress to prevent infinite loops
 * - Handle lazy and batch field protection
 */
class FieldProtectionService
{
    use ModelHelperTrait;

    /**
     * Tracks models currently being processed for field protection to prevent infinite loops.
     * Format: ['ModelClass_ID' => true]
     */
    protected static $fieldProtectionInProgress = [];

    /**
     * Registry of blocked relationships per model instance.
     * Stored statically to avoid Eloquent's __get/__set interception.
     * Format: ['ModelClass_ID' => ['relation1', 'relation2', ...]]
     */
    protected static $blockedRelationshipsRegistry = [];

    protected $bypassService;
    protected $cacheService;
    protected $teamService;

    public function __construct(
        SecurityBypassService $bypassService,
        PermissionCacheService $cacheService,
        TeamSecurityService $teamService
    ) {
        $this->bypassService = $bypassService;
        $this->cacheService = $cacheService;
        $this->teamService = $teamService;
    }

    /**
     * Check if the current user has permission for a given protection key on a model.
     * Handles team resolution, batch cache, individual cache, and hasPermission() calls.
     */
    public function hasPermissionForProtectionKey($model, string $permissionKey): bool
    {
        $teamsIdsRelated = $this->teamService->getTeamOwnersIdsSafe($model);

        if (is_null($teamsIdsRelated) || empty($teamsIdsRelated)) {
            $teamsIdsRelated = ['null'];
        }

        $hasPermission = false;

        foreach ($teamsIdsRelated as $teamId) {
            $teamCacheKey = $teamId;
            $batchCacheKey = $this->cacheService->buildBatchCacheKey(auth()->id(), $permissionKey, $teamCacheKey);

            // Check batch cache first (for collection-level loading)
            $cachedBatch = $this->cacheService->getBatchPermission($batchCacheKey);
            if ($cachedBatch !== null) {
                $hasPermission = $cachedBatch;
            } else {
                // Fall back to individual permission cache
                $permissionCacheKey = $this->cacheService->buildPermissionCacheKey($permissionKey, auth()->id(), $teamCacheKey);

                $cachedPerm = $this->cacheService->getPermissionCheck($permissionCacheKey);
                if ($cachedPerm === null) {
                    try {
                        $hasPermission = auth()->user()?->hasPermission(
                            $permissionKey,
                            PermissionTypeEnum::READ,
                            $teamsIdsRelated
                        ) ?? false;
                        $this->cacheService->setPermissionCheck($permissionCacheKey, $hasPermission);
                    } catch (\Throwable $e) {
                        Log::warning('Permission check failed', [
                            'permission_key' => $permissionKey,
                            'user_id' => auth()->id(),
                            'model_class' => get_class($model),
                            'error' => $e->getMessage()
                        ]);
                        $hasPermission = false;
                        $this->cacheService->setPermissionCheck($permissionCacheKey, false);
                    }
                } else {
                    $hasPermission = $cachedPerm;
                }
            }
        }

        return $hasPermission;
    }

    /**
     * Get sensible columns groups from model property.
     */
    public function getSensibleColumnsGroups($model): array
    {
        try {
            $groups = getPrivateProperty($model, 'sensibleColumnsGroups');
            return is_array($groups) ? $groups : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get sensible relationships groups from model property.
     */
    public function getSensibleRelationshipsGroups($model): array
    {
        try {
            $groups = getPrivateProperty($model, 'sensibleRelationshipsGroups');
            return is_array($groups) ? $groups : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Collect all protection groups for a model.
     * Returns an array of groups, each with: key, fields, type ('columns' or 'relationships').
     */
    public function collectProtectionGroups($model, string $basePermissionKey): array
    {
        // Delegate to SecurityMetadataRegistry (cached per class, computed once)
        return SecurityMetadataRegistry::for(get_class($model))['groups'];
    }

    /**
     * Apply relationship blocking for a set of relationships on a model.
     * Uses array_merge so multiple groups can block different relationships.
     */
    public function applyRelationshipBlocking($model, array $relationships): void
    {
        // Write to instance state (new approach)
        $model->getSecurityState()->blockRelationships($relationships);

        // Also write to static registry for backward compatibility with handleRetrievedEvent path
        $modelKey = $this->getModelKey($model);
        static::$blockedRelationshipsRegistry[$modelKey] = array_unique(array_merge(
            static::$blockedRelationshipsRegistry[$modelKey] ?? [], $relationships
        ));

        foreach ($relationships as $relation) {
            // Set to empty result instead of unsetting. This way relationLoaded() returns true,
            // preventing code like hasActiveBan() from re-querying blocked relations.
            $model->setRelation($relation, $this->getEmptyRelationResult($model, $relation));
        }
    }

    /**
     * Handle retrieved event with simple bypass context checking
     */
    public function handleRetrievedEvent($model, string $permissionKey): void
    {
        // If we're in a bypass context, skip all field protection
        if (SecurityBypassService::isInBypassContext()) {
            return;
        }

        $modelKey = $this->getModelKey($model);

        if ($this->hasLazyProtectedFields($model) || $this->hasBatchProtectedFields($model)) {
            return;
        }

        // Prevent infinite loops - if this model is already being processed, skip
        if (isset(static::$fieldProtectionInProgress[$modelKey])) {
            return;
        }

        // Mark as being processed
        static::$fieldProtectionInProgress[$modelKey] = true;

        try {
            $this->processFieldProtection($model, $permissionKey);
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
     * Handle getAttribute with lazy field protection
     */
    public function handleGetAttribute($model, string $attribute, $value, string $permissionKey)
    {
        // Skip all field protection when in bypass context (prevents deep recursion
        // when isSecurityBypassRequired loads related models during ownership checks)
        if (SecurityBypassService::isInBypassContext()) {
            return $value;
        }

        $modelKey = $this->getModelKey($model);

        if ($attribute == $model->getKeyName() || !empty(static::$fieldProtectionInProgress[$modelKey])) {
            return $value;
        }

        static::$fieldProtectionInProgress[$modelKey] = true;

        try {
            // Intercept blocked sensible relationships regardless of lazy/batch mode.
            if ($this->isBlockedRelationship($model, $attribute, $permissionKey)) {
                return $this->getEmptyRelationResult($model, $attribute);
            }

            if (!$this->hasLazyProtectedFields($model)) {
                return $value;
            }

            // Apply field protection logic here
            $this->processFieldProtection($model, $permissionKey);

            $protectedColumns = SecurityMetadataRegistry::for(get_class($model))['protectedColumns'];
            if (in_array($attribute, $protectedColumns)
                && !array_key_exists($attribute, $model->getRawOriginal())) {
                return null;
            }

            return $value;
        } finally {
            unset(static::$fieldProtectionInProgress[$modelKey]);
        }
    }

    /**
     * Check if an attribute is a blocked sensible relationship for this model instance.
     * Works regardless of how the model was loaded (batch, lazy, or through a relation).
     */
    public function isBlockedRelationship($model, string $attribute, string $permissionKey): bool
    {
        // Fast path: already resolved by batch processing or a previous check.
        // Safe to use because BatchPermissionService now excludes owners before blocking.
        $modelKey = $this->getModelKey($model);
        if (isset(static::$blockedRelationshipsRegistry[$modelKey])
            && in_array($attribute, static::$blockedRelationshipsRegistry[$modelKey])) {
            return true;
        }

        // Check if attribute is in any protected relationship group (cached per class via registry)
        $protectedRelationships = SecurityMetadataRegistry::for(get_class($model))['protectedRelationships'];
        if (empty($protectedRelationships) || !in_array($attribute, $protectedRelationships)) {
            return false;
        }

        // Fast bypass check only (flag + user_id match). Does NOT call usersIdsAllowedToManage()
        // or scopeUserOwnedRecords() which trigger expensive/recursive DB queries on every
        // attribute access. Full ownership checks run lazily only when strictly needed.
        if ($this->bypassService->isSecurityBypassRequiredFast($model, $this->teamService)) {
            return false;
        }

        // Check permission for each group this relationship belongs to
        foreach ($this->collectProtectionGroups($model, $permissionKey) as $group) {
            if ($group['type'] !== 'relationships' || !in_array($attribute, $group['fields'])) {
                continue;
            }

            if (!permissionMustBeAuthorized($group['key'])) {
                continue;
            }

            if (!$this->hasPermissionForProtectionKey($model, $group['key'])) {
                $this->applyRelationshipBlocking($model, $group['fields']);
                return true;
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
     * Process field protection for a model using unified protection groups.
     */
    public function processFieldProtection($model, string $permissionKey): void
    {
        $groups = $this->collectProtectionGroups($model, $permissionKey);

        foreach ($groups as $group) {
            if (!permissionMustBeAuthorized($group['key'])) continue;
            if ($this->bypassService->isSecurityBypassRequiredFast($model, $this->teamService)) continue;
            if ($this->hasPermissionForProtectionKey($model, $group['key'])) continue;

            if ($group['type'] === 'columns') {
                $this->hideSensitiveFields($model, $group['fields']);
            } else {
                $this->applyRelationshipBlocking($model, $group['fields']);
            }
        }
    }

    /**
     * Hide sensitive fields from model attributes
     */
    public function hideSensitiveFields($model, array $sensibleColumns): void
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
     * Get sensitive columns safely
     */
    public function getSensibleColumns($model): array
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
     * Check if model has lazy protected fields
     */
    public function hasLazyProtectedFields($model): bool
    {
        return getPrivateProperty($model, 'lazyProtectedFields') === true ||
               config('kompo-auth.security.lazy-protected-fields');
    }

    public function hasBatchProtectedFields($model): bool
    {
        return getPrivateProperty($model, 'batchProtectedFields') === true ||
               config('kompo-auth.security.batch-protected-fields');
    }

    /**
     * Get the sensible columns permission key using 3-step resolution:
     * 1. Check if model has getSensibleColumnsPermissionKey() method — return its value
     * 2. Check if model has $sensibleColumnsPermissionKey property — return its value
     * 3. Fall back to $basePermissionKey . '.sensibleColumns'
     */
    public function getSensibleColumnsPermissionKey($model, string $basePermissionKey): string
    {
        if (method_exists($model, 'getSensibleColumnsPermissionKey')) {
            return $model->getSensibleColumnsPermissionKey();
        }

        if (property_exists($model, 'sensibleColumnsPermissionKey')) {
            return getPrivateProperty($model, 'sensibleColumnsPermissionKey');
        }

        return $basePermissionKey . '.sensibleColumns';
    }

    /**
     * Get the sensible relationships permission key using 3-step resolution:
     * 1. Check if model has getSensibleRelationshipsPermissionKey() method — return its value
     * 2. Check if model has $sensibleRelationshipsPermissionKey property — return its value
     * 3. Fall back to $basePermissionKey . '.sensibleRelationships'
     */
    public function getSensibleRelationshipsPermissionKey($model, string $basePermissionKey): string
    {
        if (method_exists($model, 'getSensibleRelationshipsPermissionKey')) {
            return $model->getSensibleRelationshipsPermissionKey();
        }

        if (property_exists($model, 'sensibleRelationshipsPermissionKey')) {
            return getPrivateProperty($model, 'sensibleRelationshipsPermissionKey');
        }

        return $basePermissionKey . '.sensibleRelationships';
    }

    /**
     * Get sensible relationships safely
     */
    public function getSensibleRelationships($model): array
    {
        try {
            $relationships = getPrivateProperty($model, 'sensibleRelationships');
            return is_array($relationships) ? $relationships : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Check if the model opts in to discovering protection groups from the database.
     */
    protected function shouldDiscoverFromDb($model): bool
    {
        return getPrivateProperty($model, 'discoverSensibleFromDb') === true;
    }

    /**
     * Discover protection groups from the database by querying the Permission table
     * for matching sensibleColumns.* and sensibleRelationships.* patterns.
     */
    protected function discoverDbProtectionGroups($model, string $basePermissionKey): array
    {
        $groups = [];

        // Discover column-level permissions: Person.sensibleColumns.*
        $columnPrefix = $basePermissionKey . '.sensibleColumns.';
        foreach ($this->findPermissionsByPrefix($columnPrefix) as $permission) {
            $columnName = str_replace($columnPrefix, '', $permission->permission_key);
            $groups[] = [
                'key' => $permission->permission_key,
                'fields' => [$columnName],
                'type' => 'columns',
            ];
        }

        // Discover relationship-level permissions: Person.sensibleRelationships.*
        $relationPrefix = $basePermissionKey . '.sensibleRelationships.';
        foreach ($this->findPermissionsByPrefix($relationPrefix) as $permission) {
            $relationName = str_replace($relationPrefix, '', $permission->permission_key);
            $groups[] = [
                'key' => $permission->permission_key,
                'fields' => [$relationName],
                'type' => 'relationships',
            ];
        }

        return $groups;
    }

    /**
     * Find permissions by prefix using a cached LIKE query on the Permission table.
     */
    protected function findPermissionsByPrefix(string $prefix): array
    {
        $cacheKey = 'discovered_permissions_' . $prefix;

        $cached = $this->cacheService->getPermissionCheck($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $permissions = \Kompo\Auth\Models\Teams\Permission::where(
            'permission_key', 'like', $prefix . '%'
        )->get()->all();

        $this->cacheService->setPermissionCheck($cacheKey, $permissions);

        return $permissions;
    }

    /**
     * Clear all field protection tracking (called at request end)
     */
    public static function clearTracking(): void
    {
        static::$fieldProtectionInProgress = [];
        static::$blockedRelationshipsRegistry = [];
    }

    /**
     * Clear only fieldProtectionInProgress entries.
     * Used after batch processing to free memory while keeping blockedRelationshipsRegistry
     * intact for subsequent fast-path lookups during attribute access.
     */
    public static function clearInProgressTracking(): void
    {
        static::$fieldProtectionInProgress = [];
    }

    /**
     * Clean up field protection tracking for a specific model
     */
    public function cleanupModelTracking(string $modelKey): void
    {
        unset(static::$fieldProtectionInProgress[$modelKey]);
        unset(static::$blockedRelationshipsRegistry[$modelKey]);
    }
}
