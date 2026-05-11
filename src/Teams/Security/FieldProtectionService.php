<?php

namespace Kompo\Auth\Teams\Security;

use Kompo\Auth\Teams\Security\Contracts\FieldProtectionServiceInterface;
use Kompo\Auth\Teams\Security\Contracts\TeamSecurityServiceInterface;
use Kompo\Auth\Teams\Security\Traits\ModelHelperTrait;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Illuminate\Support\Facades\Log;

/**
 * Pure compute layer for field protection. Permission cache lives in
 * `CachedFieldProtectionService`. Single mode — batch on retrieval; called
 * from `BatchPermissionService` once per collection.
 */
class FieldProtectionService implements FieldProtectionServiceInterface
{
    use ModelHelperTrait;

    protected $bypassService;
    protected $teamService;

    public function __construct(
        SecurityBypassService $bypassService,
        TeamSecurityServiceInterface $teamService,
    ) {
        $this->bypassService = $bypassService;
        $this->teamService = $teamService;
    }

    public function hasPermissionForProtectionKey($model, string $permissionKey): bool
    {
        $teamsIdsRelated = $this->teamService->getTeamOwnersIdsSafe($model);

        if (empty($teamsIdsRelated)) {
            $teamsIdsRelated = ['null'];
        }

        try {
            return (bool) (auth()->user()?->hasPermission(
                $permissionKey,
                PermissionTypeEnum::READ,
                $teamsIdsRelated,
            ) ?? false);
        } catch (\Throwable $e) {
            Log::warning('Permission check failed', [
                'permission_key' => $permissionKey,
                'user_id' => auth()->id(),
                'model_class' => get_class($model),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

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
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function applyRelationshipBlocking($model, array $relationships): void
    {
        $model->getSecurityState()->blockRelationships($relationships);

        foreach ($relationships as $relation) {
            // setRelation rather than unset so relationLoaded() returns true
            // and downstream code doesn't re-query.
            $model->setRelation($relation, $this->getEmptyRelationResult($model, $relation));
        }
    }

    public function cleanupModelTracking(string $modelKey): void
    {
        // No static tracking left after the lazy-mode removal; kept on the
        // interface so the decorator can hook here for its own per-model cache.
    }

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
}
