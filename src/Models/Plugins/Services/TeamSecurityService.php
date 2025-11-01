<?php

namespace Kompo\Auth\Models\Plugins\Services;

use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Models\Plugins\Services\Traits\ModelHelperTrait;
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
class TeamSecurityService
{
    use ModelHelperTrait;

    protected $modelClass;
    protected $cacheService;

    public function __construct(string $modelClass, PermissionCacheService $cacheService)
    {
        $this->modelClass = $modelClass;
        $this->cacheService = $cacheService;
    }

    /**
     * Get team owners IDs with bypass context protection
     */
    public function getTeamOwnersIdsSafe($model)
    {
        try {
            // Use cached result if available for this model instance
            $modelKey = $this->getModelKey($model);
            $cacheKey = "team_owners_{$modelKey}";

            $cachedValue = $this->cacheService->getPermissionCheck($cacheKey);
            if ($cachedValue !== null) {
                return $cachedValue;
            }

            $result = $this->calculateTeamOwnersIds($model);
            $this->cacheService->setPermissionCheck($cacheKey, $result);

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
        $wasInBypassContext = SecurityBypassService::isInBypassContext();
        SecurityBypassService::enterBypassContext();

        try {
            // Strategy 1: Custom method
            if ($this->modelHasMethod($model, 'securityRelatedTeamIds')) {
                SecurityBypassService::enterBypassContext();
                $teamIds = callPrivateMethod($model, 'securityRelatedTeamIds');
                SecurityBypassService::exitBypassContext();

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
            if (!$wasInBypassContext) {
                SecurityBypassService::exitBypassContext();
            }
        }
    }

    /**
     * Check if owned records should be validated (owner bypass disabled)
     */
    public function shouldValidateOwnedRecords($model): bool
    {
        // Check model-specific property first
        if (property_exists($model, 'validateOwnedAsWell')) {
            return getPrivateProperty($model, 'validateOwnedAsWell');
        }

        // Fall back to global config
        return config('kompo-auth.security.default-validate-owned-as-well', false);
    }

    /**
     * Check if mass restrict by team applies (for read security scopes)
     */
    public function massRestrictByTeam(): bool
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

    /**
     * Check if individual restrict by team applies (for write/delete operations)
     */
    public function individualRestrictByTeam($model): bool
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

    /**
     * Get team ID column
     */
    public function getTeamIdColumn(): ?string
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
}
