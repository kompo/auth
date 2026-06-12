<?php

namespace Kompo\Auth\Teams\Security;

use Condoedge\Utils\Contracts\Security\HasOwnedRecords;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Teams\Contracts\PermissionResolverInterface;
use Kompo\Auth\Teams\Security\Contracts\FieldProtectionServiceInterface;
use Kompo\Auth\Teams\Security\Contracts\OwnedRecordsResolverInterface;
use Kompo\Auth\Teams\Security\Contracts\TeamSecurityServiceInterface;
use Kompo\Auth\Teams\Security\SecurityMetadataRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Handles batch permission loading for collections
 *
 * Responsibilities:
 * - Batch load field protection permissions for collections
 * - Group models by teams for efficient processing
 * - Calculate processing plans based on team intersections
 * - Execute batch permission checks via the cached PermissionResolver
 *
 * Layering note (Phase 5):
 *   This service used to keep its own per-request permission cache in
 *   PermissionCacheService (buildBatchCacheKey / getBatchPermission /
 *   setBatchPermission). That was redundant — `User->hasPermission` already
 *   delegates to `CachedPermissionResolver`, which caches per request. The
 *   local cache layer is removed; checks now go straight through
 *   PermissionResolverInterface.
 */
class BatchPermissionService
{
    protected $permissionResolver;
    protected $teamService;
    protected $fieldProtectionService;
    protected $bypassService;

    public function __construct(
        PermissionResolverInterface $permissionResolver,
        TeamSecurityServiceInterface $teamService,
        FieldProtectionServiceInterface $fieldProtectionService,
        SecurityBypassService $bypassService
    ) {
        $this->permissionResolver = $permissionResolver;
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

        // Mixed-class collections (e.g. a parent model hydrating rows into
        // subclasses) must be partitioned: groups and owned-records below are
        // class-level metadata and would otherwise all come from the first model.
        $byClass = $modelsCollection->groupBy(fn ($m) => get_class($m));
        if ($byClass->count() > 1) {
            foreach ($byClass as $classModels) {
                $this->batchLoadFieldProtectionPermissions($classModels->values());
            }
            return $modelsCollection->all();
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

        // Bulk owner pre-resolution: ask OwnedRecordsResolver once for the
        // whole collection's owned IDs and mark matches as bypassed.
        $this->bulkResolveOwnedModels($modelsCollection, $firstModel);

        // Bulk team-owner pre-resolution: for classes that implement
        // BulkResolvableTeamOwners, resolve the whole batch's owning team_ids
        // in one query and seed the per-request cache. The per-instance
        // `getTeamOwnersIdsSafe` calls inside `groupModelsByTeams` then hit
        // memory instead of firing one query per model. No-op for the inner
        // (uncached) resolver and for classes without the bulk contract.
        $this->teamService->prewarmTeamOwners($modelsCollection);

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
     * Process a single protection group across all models using team-intersection batch logic.
     *
     * For each team that needs a check, ask PermissionResolverInterface once.
     * The resolver caches the answer for the request; subsequent calls (and
     * other code paths that go through `User->hasPermission`) share the same
     * cached result.
     */
    protected function batchProcessGroup($modelsCollection, array $group, int $userId, $user): void
    {
        $teamModelMap = $this->groupModelsByTeams($modelsCollection);
        $authorizedTeams = $this->getAuthorizedTeams($user, $group['key']);
        $processingPlan = $this->calculateProcessingPlan($teamModelMap, $authorizedTeams);

        foreach ($processingPlan['needs_check'] as $teamKey => $models) {
            $teamId = $teamKey === 'no_team' ? null : (int) str_replace('team_', '', $teamKey);

            if ($this->resolverAllows($userId, $group['key'], $teamId)) {
                continue;
            }

            foreach ($models as $model) {
                if (!$this->isModelBypassed($model)) {
                    $this->applyGroupProtection($model, $group);
                }
            }
        }

        // unauthorized_models is populated only by legacy callers; calculateProcessingPlan
        // does not currently fill it. Kept for forward compatibility.
        foreach ($processingPlan['unauthorized_models'] as $model) {
            if (!$this->isModelBypassed($model)) {
                $this->applyGroupProtection($model, $group);
            }
        }
    }

    /**
     * Ask the cached resolver whether the user has $permissionKey for the given team.
     * Single seam for both the per-team and the no-team checks.
     */
    protected function resolverAllows(int $userId, string $permissionKey, ?int $teamId): bool
    {
        try {
            return $this->permissionResolver->userHasPermission(
                $userId,
                $permissionKey,
                PermissionTypeEnum::READ,
                $teamId,
            );
        } catch (\Throwable $e) {
            Log::warning('Batch permission check failed', [
                'permission_key' => $permissionKey,
                'user_id' => $userId,
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);
            return false;
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
     * Fast O(1) bypass check (read-flag + user_id match). Bulk owner
     * resolution happens once per collection in `bulkResolveOwnedModels`.
     */
    protected function isModelBypassed($model): bool
    {
        $state = $model->getSecurityState();

        if ($state->bypassed === true) {
            return true;
        }

        $bypassed = $this->bypassService->isSecurityBypassRequiredFast($model, $this->teamService);

        if ($bypassed) {
            $state->bypassed = true;
        }

        return $bypassed;
    }

    /**
     * Resolve owned records via OwnedRecordsResolverInterface — one resolver
     * call per (user, modelClass) for the request. Matches are flagged as
     * bypassed on ModelSecurityState.
     */
    protected function bulkResolveOwnedModels($modelsCollection, $firstModel): void
    {
        if (!auth()->check()) {
            return;
        }

        $modelClass = get_class($firstModel);

        if (!is_subclass_of($modelClass, HasOwnedRecords::class)) {
            return;
        }

        $ownedIds = array_flip(
            app(OwnedRecordsResolverInterface::class)->forUser(auth()->id(), $modelClass)
        );

        if (empty($ownedIds)) {
            return;
        }

        foreach ($modelsCollection as $model) {
            if (isset($ownedIds[$model->getKey()])) {
                $model->getSecurityState()->bypassed = true;
            }
        }
    }
}
