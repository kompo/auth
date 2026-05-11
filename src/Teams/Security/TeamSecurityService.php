<?php

namespace Kompo\Auth\Teams\Security;

use Kompo\Auth\Contracts\Security\ScopedToTeam;
use Kompo\Auth\Teams\Security\Contracts\TeamSecurityServiceInterface;
use Kompo\Auth\Teams\Security\Traits\ModelHelperTrait;
use Illuminate\Support\Facades\Log;

/**
 * Handles team-related security logic
 *
 * Responsibilities:
 * - Calculate team owners IDs for models
 * - Check team restrictions
 * - Get team ID column
 * - Validate owned records settings
 */
class TeamSecurityService implements TeamSecurityServiceInterface
{
    use ModelHelperTrait;

    protected $modelClass;

    public function __construct(string $modelClass)
    {
        $this->modelClass = $modelClass;
    }

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
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Team owners IDs come from `ScopedToTeam::getRelatedTeamIds`. Uncontracted
     * models return null (no team binding). The Team model itself implements
     * the contract and returns `[id, parent_team_id]`.
     */
    protected function calculateTeamOwnersIds($model)
    {
        if (!$model instanceof ScopedToTeam) {
            return null;
        }

        $wasInBypassContext = SecurityBypassService::isInBypassContext();
        SecurityBypassService::enterBypassContext();

        try {
            return array_values($model->getRelatedTeamIds());
        } finally {
            if (!$wasInBypassContext) {
                SecurityBypassService::exitBypassContext();
            }
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
     * Check if mass restrict by team applies (for read security scopes).
     *
     * Reads class-stable values from SecurityMetadataRegistry — the previous
     * implementation did `new ($this->modelClass)` per query (every Eloquent
     * read goes through the auth scope) just to reflect on a non-static
     * property. Now resolved once at metadata compute time.
     */
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
    public function massRestrictByTeam(): bool
    {
        $meta = SecurityMetadataRegistry::for($this->modelClass);

        if ($meta['optedOutOfTeamScope']) {
            return false;
        }

        return $meta['usesScopedToTeamContract'] || $meta['autoTeamIdColumn'] !== null;
    }

    /**
     * Whether write/delete operations should team-check this instance.
     */
    public function individualRestrictByTeam($model): bool
    {
        return (bool) SecurityMetadataRegistry::for(get_class($model))['usesScopedToTeamContract'];
    }
}
