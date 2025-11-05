<?php

namespace Kompo\Auth\Models\Plugins\Services;

use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Illuminate\Support\Facades\Log;

/**
 * Handles batch permission loading for collections
 *
 * Responsibilities:
 * - Batch load field protection permissions for collections
 * - Group models by teams for efficient processing
 * - Calculate processing plans based on team intersections
 * - Execute batch permission checks
 */
class BatchPermissionService
{
    protected $cacheService;
    protected $teamService;
    protected $fieldProtectionService;

    public function __construct(
        PermissionCacheService $cacheService,
        TeamSecurityService $teamService,
        FieldProtectionService $fieldProtectionService
    ) {
        $this->cacheService = $cacheService;
        $this->teamService = $teamService;
        $this->fieldProtectionService = $fieldProtectionService;
    }

    /**
     * Batch load field protection permissions for a collection of models
     * This prevents N+1 queries when retrieving collections
     */
    public function batchLoadFieldProtectionPermissions($models): array
    {
        if (empty($models)) {
            return [];
        }

        $user = auth()->user();
        $userId = $user?->id;

        if (!$user) {
            return collect($models)->map(function ($model) {
                $sensibleColumns = $this->fieldProtectionService->getSensibleColumns($model);
                $this->fieldProtectionService->hideSensitiveFields($model, $sensibleColumns);

                return $model;
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
            return collect($models)->all();
        }

        // Set context for cache lookups
        PermissionCacheService::setCurrentBatchPermissionKey($sensibleColumnsKey);

        try {
            return $this->batchLoadWithTeamIntersections($modelsCollection, $sensibleColumnsKey, $userId, $user);
        } finally {
            // Clean up context
            PermissionCacheService::setCurrentBatchPermissionKey(null);
        }
    }

    /**
     * Advanced batch loading with team intersections for optimal performance
     */
    protected function batchLoadWithTeamIntersections($modelsCollection, string $sensibleColumnsKey, int $userId, $user): array
    {
        if (!count($this->fieldProtectionService->getSensibleColumns($modelsCollection->first()))) {
            // No sensible columns to protect
            return $modelsCollection->all();
        }

        // Step 1: Group models by their team associations
        $teamModelMap = $this->groupModelsByTeams($modelsCollection);

        // Step 2: Get teams where user has permissions (pre-filter)
        $authorizedTeams = $this->getAuthorizedTeams($user, $sensibleColumnsKey);

        // Step 3: Calculate intersections and determine which models need processing
        $processingPlan = $this->calculateProcessingPlan($teamModelMap, $authorizedTeams);

        // Step 4: Batch load only the necessary permission checks
        $this->executeBatchPermissionChecks($processingPlan, $user, $sensibleColumnsKey, $userId);

        // Step 5: Apply field protection based on the results
        return $this->applyFieldProtectionFromPlan($modelsCollection, $processingPlan);
    }

    /**
     * Group models by their team associations for efficient processing
     */
    protected function groupModelsByTeams($modelsCollection): array
    {
        $teamModelMap = [];

        foreach ($modelsCollection as $model) {
            $teamIds = $this->teamService->getTeamOwnersIdsSafe($model);

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
    protected function getAuthorizedTeams($user, string $sensibleColumnsKey): array
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
     */
    protected function calculateProcessingPlan(array $teamModelMap, array $authorizedTeams): array
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
    protected function executeBatchPermissionChecks(array $processingPlan, $user, string $sensibleColumnsKey, int $userId): void
    {
        foreach ($processingPlan['needs_check'] as $teamKey => $models) {
            if ($teamKey === 'no_team') {
                // Check global permission for no-team models
                $batchCacheKey = $this->cacheService->buildBatchCacheKey($userId, $sensibleColumnsKey, null);

                if ($this->cacheService->getBatchPermission($batchCacheKey) === null) {
                    try {
                        $hasPermission = $user->hasPermission(
                            $sensibleColumnsKey,
                            PermissionTypeEnum::READ,
                            null
                        ) ?? false;
                        $this->cacheService->setBatchPermission($batchCacheKey, $hasPermission);
                    } catch (\Throwable $e) {
                        Log::warning('Batch permission check failed for no-team models', [
                            'permission_key' => $sensibleColumnsKey,
                            'user_id' => $userId,
                            'error' => $e->getMessage()
                        ]);
                        $this->cacheService->setBatchPermission($batchCacheKey, false);
                    }
                }
            } else {
                // Extract team ID from team key (team_123 -> 123)
                $teamId = str_replace('team_', '', $teamKey);
                $batchCacheKey = $this->cacheService->buildBatchCacheKey($userId, $sensibleColumnsKey, $teamId);

                if ($this->cacheService->getBatchPermission($batchCacheKey) === null) {
                    try {
                        $hasPermission = $user->hasPermission(
                            $sensibleColumnsKey,
                            PermissionTypeEnum::READ,
                            $teamId
                        ) ?? false;
                        $this->cacheService->setBatchPermission($batchCacheKey, $hasPermission);
                    } catch (\Throwable $e) {
                        Log::warning('Batch permission check failed for team', [
                            'permission_key' => $sensibleColumnsKey,
                            'user_id' => $userId,
                            'team_id' => $teamId,
                            'error' => $e->getMessage()
                        ]);
                        $this->cacheService->setBatchPermission($batchCacheKey, false);
                    }
                }
            }
        }
    }

    /**
     * Apply field protection based on the processing plan results
     */
    protected function applyFieldProtectionFromPlan($modelsCollection, array $processingPlan): array
    {
        $processedModels = [];

        // Process authorized models (no field hiding needed)
        foreach ($processingPlan['authorized_models'] as $model) {
            $processedModels[] = $model; // Keep as-is, they have permission
        }

        // Process models that need individual checks
        foreach ($processingPlan['needs_check'] as $teamKey => $models) {
            $hasPermission = $this->getPermissionFromCache($teamKey, auth()->id());

            foreach ($models as $model) {
                if (!$hasPermission) {
                    // Hide sensitive fields
                    $sensibleColumns = $this->fieldProtectionService->getSensibleColumns($model);
                    $this->fieldProtectionService->hideSensitiveFields($model, $sensibleColumns);
                }
                $processedModels[] = $model;
            }
        }

        // Process unauthorized models (hide fields)
        foreach ($processingPlan['unauthorized_models'] as $model) {
            $sensibleColumns = $this->fieldProtectionService->getSensibleColumns($model);
            $this->fieldProtectionService->hideSensitiveFields($model, $sensibleColumns);
            $processedModels[] = $model;
        }

        return $processedModels;
    }

    /**
     * Get permission result from cache based on team key
     */
    protected function getPermissionFromCache(string $teamKey, int $userId): bool
    {
        $permissionKey = PermissionCacheService::getCurrentBatchPermissionKey();

        if ($teamKey === 'no_team') {
            $cacheKey = $this->cacheService->buildBatchCacheKey($userId, $permissionKey, null);
        } else {
            $teamId = str_replace('team_', '', $teamKey);
            $cacheKey = $this->cacheService->buildBatchCacheKey($userId, $permissionKey, $teamId);
        }

        return $this->cacheService->getBatchPermission($cacheKey) ?? false;
    }
}
