<?php

namespace Kompo\Auth\Teams\Security\Contracts;

/**
 * Contract for team-scoped security resolution.
 *
 * Implementations answer team-related questions about a given model instance
 * or model class: which teams own this record, is the model team-scoped,
 * should owner bypass be disabled, etc.
 *
 * Pure resolvers MUST NOT cache. Caching is a separate decorator layer
 * (see Teams\Cache\CachedTeamSecurityService, Phase 3).
 */
interface TeamSecurityServiceInterface
{
    /**
     * Return the team IDs that "own" the given model instance, or null when
     * the model has no team association. Implementations choose how to derive
     * this (column, relation, custom method, etc.).
     *
     * @return array<int>|null
     */
    public function getTeamOwnersIdsSafe($model);

    /**
     * Whether the model opts out of owner bypass — `EnforcesStrictPermissions`
     * or the config default.
     */
    public function shouldValidateOwnedRecords($model): bool;

    /**
     * Whether the model class participates in team-based read scopes —
     * i.e. it implements `ScopedToTeam`.
     */
    public function massRestrictByTeam(): bool;

    /**
     * Whether the given model instance participates in team-based write/delete checks.
     */
    public function individualRestrictByTeam($model): bool;
}
