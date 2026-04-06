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

        // Convert to collection if array
        $modelsCollection = collect($models);
        $firstModel = $modelsCollection->first();
        if (!$firstModel) {
            return [];
        }

        $modelClass = get_class($firstModel);
        $permissionKey = $this->resolvePermissionKey($firstModel, $modelClass);
        $groups = $this->fieldProtectionService->collectProtectionGroups($firstModel, $permissionKey);

        if (empty($groups)) {
            return $modelsCollection->all();
        }

        // If no user, apply all protections (hide columns, block relationships)
        if (!$user) {
            foreach ($modelsCollection as $model) {
                foreach ($groups as $group) {
                    $this->applyGroupProtection($model, $group);
                }
            }
            return $modelsCollection->all();
        }

        // Process each group using the batch team-intersection approach
        foreach ($groups as $group) {
            if (!permissionMustBeAuthorized($group['key'])) continue;
            $this->batchProcessGroup($modelsCollection, $group, $userId, $user);
        }

        return $modelsCollection->all();
    }

    /**
     * Process a single protection group across all models using team-intersection batch logic
     */
    protected function batchProcessGroup($modelsCollection, array $group, int $userId, $user): void
    {
        PermissionCacheService::setCurrentBatchPermissionKey($group['key']);

        try {
            $teamModelMap = $this->groupModelsByTeams($modelsCollection);
            $authorizedTeams = $this->getAuthorizedTeams($user, $group['key']);
            $processingPlan = $this->calculateProcessingPlan($teamModelMap, $authorizedTeams);
            $this->executeBatchPermissionChecks($processingPlan, $user, $group['key'], $userId);

            // Apply protection to models that lack permission
            foreach ($processingPlan['needs_check'] as $teamKey => $models) {
                $hasPermission = $this->getPermissionFromCache($teamKey, $userId);
                if (!$hasPermission) {
                    foreach ($models as $model) {
                        $this->applyGroupProtection($model, $group);
                    }
                }
            }

            foreach ($processingPlan['unauthorized_models'] as $model) {
                $this->applyGroupProtection($model, $group);
            }
        } finally {
            PermissionCacheService::setCurrentBatchPermissionKey(null);
        }
    }

    /**
     * Apply the appropriate protection (column hiding or relationship blocking) for a group
     */
    protected function applyGroupProtection($model, array $group): void
    {
        if ($group['type'] === 'columns') {
            $this->fieldProtectionService->hideSensitiveFields($model, $group['fields']);
        } else {
            $this->fieldProtectionService->applyRelationshipBlocking($model, $group['fields']);
        }
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

    /**
     * Resolve permission key using 3-step resolution:
     * 1. Check if model has getPermissionKey() method
     * 2. Check if model has $permissionKey property
     * 3. Fall back to class_basename()
     */
    protected function resolvePermissionKey($model, string $modelClass): string
    {
        if (method_exists($model, 'getPermissionKey')) {
            return $model->getPermissionKey();
        }

        if (property_exists($model, 'permissionKey')) {
            return getPrivateProperty($model, 'permissionKey');
        }

        return class_basename($modelClass);
    }
}
