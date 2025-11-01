<?php

namespace Kompo\Auth\Models\Plugins\Services;

use Kompo\Auth\Models\Plugins\Services\Traits\ModelHelperTrait;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Illuminate\Support\Facades\Log;

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
     * Handle retrieved event with simple bypass context checking
     */
    public function handleRetrievedEvent($model, string $permissionKey): void
    {
        // If we're in a bypass context, skip all field protection
        if (SecurityBypassService::isInBypassContext()) {
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
        if ($attribute == $model->getKeyName() || !empty(static::$fieldProtectionInProgress[$this->getModelKey($model)])) {
            return $value;
        }

        static::$fieldProtectionInProgress[$this->getModelKey($model)] = true;

        if (!$this->hasLazyProtectedFields($model)) {
            static::$fieldProtectionInProgress[$this->getModelKey($model)] = false;
            return $value;
        }

        $sensibleColumnsKey = $permissionKey . '.sensibleColumns';

        // Apply field protection logic here
        $this->processFieldProtection($model, $permissionKey);

        $permCacheKey = 'user_permission_' . $sensibleColumnsKey . '_' . auth()->id();
        if (in_array($attribute, $this->getSensibleColumns($model)) &&
            ($this->cacheService->getPermissionCheck($permCacheKey) ?? null) === false) {
            static::$fieldProtectionInProgress[$this->getModelKey($model)] = false;
            return null;
        }

        static::$fieldProtectionInProgress[$this->getModelKey($model)] = false;

        return $value;
    }

    /**
     * Handle getAttributes with lazy field protection
     */
    public function handleGetAttributes($model, array $attributes, string $permissionKey): array
    {
        if (!empty(static::$fieldProtectionInProgress[class_basename($model)])) {
            return $attributes;
        }

        static::$fieldProtectionInProgress[class_basename($model)] = true;

        if (!$this->hasLazyProtectedFields($model)) {
            static::$fieldProtectionInProgress[class_basename($model)] = false;
            return $attributes;
        }

        $sensibleColumnsKey = $permissionKey . '.sensibleColumns';

        // Early exit if no sensible columns permission exists
        if (!permissionMustBeAuthorized($sensibleColumnsKey)) {
            return $attributes;
        }

        // Skip if security bypass is required (simple check)
        if ($this->bypassService->isSecurityBypassRequired($model, $this->teamService)) {
            return $attributes;
        }

        // Apply field protection logic here
        $this->processFieldProtection($model, $permissionKey);

        static::$fieldProtectionInProgress[class_basename($model)] = false;

        return $attributes;
    }

    /**
     * Process field protection for a model
     */
    public function processFieldProtection($model, string $permissionKey): void
    {
        $sensibleColumnsKey = $permissionKey . '.sensibleColumns';

        // Early exit if no sensible columns permission exists
        if (!permissionMustBeAuthorized($sensibleColumnsKey)) {
            return;
        }

        // Skip if security bypass is required (simple check)
        if ($this->bypassService->isSecurityBypassRequired($model, $this->teamService)) {
            return;
        }

        $this->removeSensitiveFields($model, $sensibleColumnsKey);
    }

    /**
     * Remove sensitive fields with enhanced safety measures
     */
    protected function removeSensitiveFields($model, string $sensibleColumnsKey): void
    {
        // Check if model has sensitive columns defined
        if (!property_exists($model, 'sensibleColumns')) {
            return;
        }

        $sensibleColumns = $this->getSensibleColumns($model);
        if (empty($sensibleColumns)) {
            return;
        }

        // Get team context safely (without triggering field protection)
        $teamsIdsRelated = $this->teamService->getTeamOwnersIdsSafe($model);

        if (is_null($teamsIdsRelated) || empty($teamsIdsRelated)) {
            $teamsIdsRelated = ['null'];
        }

        foreach ($teamsIdsRelated as $teamId) {
            // Build proper cache key including team context
            $teamCacheKey = $teamId;
            $batchCacheKey = $this->cacheService->buildBatchCacheKey(auth()->id(), $sensibleColumnsKey, $teamCacheKey);

            // Check batch cache first (for collection-level loading)
            $cachedBatch = $this->cacheService->getBatchPermission($batchCacheKey);
            if ($cachedBatch !== null) {
                $hasPermission = $cachedBatch;
            } else {
                // Fall back to individual permission cache
                $permissionCacheKey = $this->cacheService->buildPermissionCacheKey($sensibleColumnsKey, auth()->id(), $teamCacheKey);

                $cachedPerm = $this->cacheService->getPermissionCheck($permissionCacheKey);
                if ($cachedPerm === null) {
                    try {
                        $hasPermission = auth()->user()?->hasPermission(
                            $sensibleColumnsKey,
                            PermissionTypeEnum::READ,
                            $teamsIdsRelated
                        ) ?? false;
                        $this->cacheService->setPermissionCheck($permissionCacheKey, $hasPermission);
                    } catch (\Throwable $e) {
                        Log::warning('Permission check failed for sensitive columns', [
                            'permission_key' => $sensibleColumnsKey,
                            'user_id' => auth()->id(),
                            'model_class' => get_class($model),
                            'error' => $e->getMessage()
                        ]);
                        // Default to hiding sensitive data if permission check fails
                        $hasPermission = false;
                        $this->cacheService->setPermissionCheck($permissionCacheKey, false);
                    }
                } else {
                    $hasPermission = $cachedPerm;
                }
            }
            $teamCacheKey = $teamId;
        }

        // Remove sensitive fields if permission check fails
        if (!$hasPermission) {
            $this->hideSensitiveFields($model, $sensibleColumns);
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
    protected function hasLazyProtectedFields($model): bool
    {
        return getPrivateProperty($model, 'lazyProtectedFields') === true ||
               config('kompo-auth.security.lazy-protected-fields');
    }

    /**
     * Clear field protection tracking
     */
    public static function clearTracking(): void
    {
        static::$fieldProtectionInProgress = [];
    }

    /**
     * Get field protection in progress count
     */
    public static function getInProgressCount(): int
    {
        return count(static::$fieldProtectionInProgress);
    }

    /**
     * Clean up field protection tracking for a specific model
     */
    public function cleanupModelTracking(string $modelKey): void
    {
        unset(static::$fieldProtectionInProgress[$modelKey]);
    }
}
