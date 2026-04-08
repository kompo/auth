<?php

namespace Kompo\Auth\Models\Plugins\Services;

use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Plugins\Services\SecurityMetadataRegistry;
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
    protected $bypassService;

    public function __construct(
        PermissionCacheService $cacheService,
        TeamSecurityService $teamService,
        FieldProtectionService $fieldProtectionService,
        SecurityBypassService $bypassService
    ) {
        $this->cacheService = $cacheService;
        $this->teamService = $teamService;
        $this->fieldProtectionService = $fieldProtectionService;
        $this->bypassService = $bypassService;
    }

    /**
     * Batch load field protection permissions for a collection of models.
     * Uses SecurityMetadataRegistry for class-level metadata and ModelSecurityState
     * for per-instance state instead of static arrays.
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

        // Use SecurityMetadataRegistry for class-level metadata (cached, O(1))
        $meta = SecurityMetadataRegistry::for(get_class($firstModel));
        $groups = $meta['groups'];

        if (empty($groups)) {
            // Mark all models as resolved even when no groups
            foreach ($modelsCollection as $model) {
                $model->getSecurityState()->protectionResolved = true;
            }
            return $modelsCollection->all();
        }

        // If no user, apply protections only to non-bypassed models
        if (!$user) {
            foreach ($modelsCollection as $model) {
                if ($this->isModelBypassed($model)) {
                    continue;
                }
                foreach ($groups as $group) {
                    $this->applyGroupProtection($model, $group);
                }
                $model->getSecurityState()->protectionResolved = true;
            }
            return $modelsCollection->all();
        }

        // Process each group using the batch team-intersection approach
        foreach ($groups as $group) {
            if (!permissionMustBeAuthorized($group['key'])) continue;
            $this->batchProcessGroup($modelsCollection, $group, $userId, $user);
        }

        // Mark all models as protection-resolved
        foreach ($modelsCollection as $model) {
            $model->getSecurityState()->protectionResolved = true;
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

            // Apply protection to models that lack permission AND are not bypassed (owners, etc.)
            foreach ($processingPlan['needs_check'] as $teamKey => $models) {
                $hasPermission = $this->getPermissionFromCache($teamKey, $userId);
                if (!$hasPermission) {
                    foreach ($models as $model) {
                        if (!$this->isModelBypassed($model)) {
                            $this->applyGroupProtection($model, $group);
                        }
                    }
                }
            }

            foreach ($processingPlan['unauthorized_models'] as $model) {
                if (!$this->isModelBypassed($model)) {
                    $this->applyGroupProtection($model, $group);
                }
            }
        } finally {
            PermissionCacheService::setCurrentBatchPermissionKey(null);
        }
    }

    /**
     * Apply the appropriate protection (column hiding or relationship blocking) for a group.
     * For relationships, writes to ModelSecurityState AND sets relation on model.
     */
    protected function applyGroupProtection($model, array $group): void
    {
        if ($group['type'] === 'columns') {
            $this->fieldProtectionService->hideSensitiveFields($model, $group['fields']);
        } else {
            // applyRelationshipBlocking writes to both ModelSecurityState AND static registry,
            // and sets relations on model so relationLoaded() returns true
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
     * Check if a model is bypassed using fast O(1) checks only (flag + user_id match).
     * Writes result to ModelSecurityState for O(1) lookups in getAttribute/interceptRelation.
     * Does NOT call usersIdsAllowedToManage() or scopeUserOwnedRecords() which cause
     * N+1 query explosions (10+ queries per model). Those expensive checks run lazily
     * when individual attributes are accessed.
     */
    protected function isModelBypassed($model): bool
    {
        $state = $model->getSecurityState();

        if ($state->bypassed !== null) {
            return $state->bypassed;
        }

        $bypassed = $this->bypassService->isSecurityBypassRequiredFast($model, $this->teamService);
        $state->bypassed = $bypassed;

        return $bypassed;
    }

}
