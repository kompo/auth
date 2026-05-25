<?php

namespace Kompo\Auth\Contracts\Security;

use Condoedge\Utils\Contracts\Security\ScopedToTeam as BaseScopedToTeam;

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
interface ScopedToTeam extends BaseScopedToTeam
{
}
