<?php

namespace Kompo\Auth\Contracts\Security;

use Illuminate\Database\Eloquent\Builder;

/**
 * The model is filterable by team. Replaces the `$restrictByTeam` flag,
 * `$TEAM_ID_COLUMN`, `team_id` auto-detect, `team()` relation auto-detect,
 * `scopeSecurityForTeams`, `scopeSecurityForTeamByQuery`, and
 * `securityRelatedTeamIds()`.
 *
 * Absence of the contract → no team filtering. Permission key alone gates access.
 *
 * Ready-made traits:
 *   - `Kompo\Auth\Models\Concerns\Security\BelongsToOneTeam`
 *   - `Kompo\Auth\Models\Concerns\Security\BelongsToManyTeams` (TODO)
 */
interface ScopedToTeam
{
    /**
     * Apply the team filter to a read query. The implementer picks the shape
     * (whereIn / whereHas / subquery / column).
     *
     * @param array<int> $teamIds Team IDs the viewer is allowed to see.
     */
    public function applyTeamSecurityScope(Builder $query, array $teamIds): void;

    /**
     * Team IDs this instance belongs to. Used by write/delete checks and by
     * `TeamSecurityService::getTeamOwnersIdsSafe`.
     *
     * @return array<int>
     */
    public function getRelatedTeamIds(): array;
}
