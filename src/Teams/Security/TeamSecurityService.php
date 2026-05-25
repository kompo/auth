<?php

namespace Kompo\Auth\Teams\Security;

use Condoedge\Utils\Contracts\Security\ScopedToTeam;
use Kompo\Auth\Teams\Security\Contracts\TeamSecurityServiceInterface;
use Illuminate\Support\Facades\Log;

/**
 * Handles team-related security logic.
 *
 * Stateless — every method derives the relevant model class from its argument
 * (model instance for instance-level methods, explicit string for the
 * class-level `massRestrictByTeam`). Safe to bind as a singleton.
 *
 * Responsibilities:
 * - Calculate team owners IDs for a model instance
 * - Decide if owner-bypass applies
 * - Decide if read/write/delete team-restriction applies
 */
class TeamSecurityService implements TeamSecurityServiceInterface
{
    /**
     * Get team owners IDs with bypass context protection.
     *
     * Pure resolver: no caching. The CachedTeamSecurityService decorator wraps
     * this call with a per-request, per-instance cache. Callers should resolve
     * TeamSecurityServiceInterface from the container to get the cached version.
     */
    public function getTeamOwnersIdsSafe($model)
    {
        try {
            return $this->calculateTeamOwnersIds($model);
        } catch (\Throwable $e) {
            Log::warning('Failed to get team owners IDs safely', [
                'model_class' => get_class($model),
                'model_id' => $model->getKey(),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Team owners IDs come from `ScopedToTeam::getRelatedTeamIds`. Models
     * without the contract fall back to the auto-detected `team_id` column
     * (parallel to the read-scope behavior). The Team model itself implements
     * the contract and returns `[id, parent_team_id]`.
     */
    protected function calculateTeamOwnersIds($model)
    {
        // Reference-counted bypass — pair every enter with an exit. With the
        // counter, nesting is safe: depth increments here and decrements in
        // the finally; outer bypass (if any) is preserved.
        SecurityBypassService::enterBypassContext();

        try {
            if ($model instanceof ScopedToTeam) {
                return array_values($model->getRelatedTeamIds());
            }

            $autoCol = SecurityMetadataRegistry::for(get_class($model))['autoTeamIdColumn'] ?? null;
            if ($autoCol !== null) {
                $value = $model->getAttribute($autoCol);
                return $value === null ? [] : [$value];
            }

            return null;
        } finally {
            SecurityBypassService::exitBypassContext();
        }
    }

    /**
     * Ownership grants nothing when the model enforces strict permissions
     * (`EnforcesStrictPermissions` contract). Config default for non-marked
     * models.
     */
    public function shouldValidateOwnedRecords($model): bool
    {
        if (SecurityMetadataRegistry::for(get_class($model))['enforcesStrictPermissions']) {
            return true;
        }

        return (bool) kompoAuthSecurityConfig('owned_records.validate_as_well', false);
    }

    /**
     * Whether the read scope should apply team filtering for this model class.
     *
     * True for:
     *   - Models implementing `ScopedToTeam` (preferred).
     *   - Models with an auto-detected `team_id` column (fallback, warned).
     *
     * False when the model implements `NoTeamScope` (explicit opt-out) or has
     * neither contract nor column.
     */
    public function massRestrictByTeam(string $modelClass): bool
    {
        $meta = SecurityMetadataRegistry::for($modelClass);

        if ($meta['optedOutOfTeamScope']) {
            return false;
        }

        return $meta['usesScopedToTeamContract'] || $meta['autoTeamIdColumn'] !== null;
    }

    /**
     * Whether write/delete operations should team-check this instance.
     * True for contract-bound models or those with an auto-detected `team_id`
     * column — same pair as the bulk read scope.
     */
    public function individualRestrictByTeam($model): bool
    {
        $meta = SecurityMetadataRegistry::for(get_class($model));

        if ($meta['optedOutOfTeamScope']) {
            return false;
        }

        return $meta['usesScopedToTeamContract'] || $meta['autoTeamIdColumn'] !== null;
    }
}
