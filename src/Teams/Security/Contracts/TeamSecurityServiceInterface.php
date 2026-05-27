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
     * Whether the given model class participates in team-based read scopes
     * (implements `ScopedToTeam` or has an auto-detected `team_id` column,
     * and isn't opted out via `NoTeamScope`). Class is passed at call time so
     * implementations can be shared across model classes (singleton-friendly).
     */
    public function massRestrictByTeam(string $modelClass): bool;

    /**
     * Whether the given model instance participates in team-based write/delete checks.
     */
    public function individualRestrictByTeam($model): bool;

    /**
     * Optionally pre-resolve `getTeamOwnersIdsSafe` for many models in one go.
     *
     * Callers that already have a collection (e.g. BatchPermissionService)
     * pass it here before the per-instance security loop. Implementations
     * that don't cache MAY no-op. Implementations that DO cache (the cached
     * decorator) bulk-resolve via the optional `BulkResolvableTeamOwners`
     * contract and seed the per-request cache so subsequent
     * `getTeamOwnersIdsSafe` calls hit memory instead of the DB.
     *
     * @param  iterable<\Illuminate\Database\Eloquent\Model> $models
     */
    public function prewarmTeamOwners(iterable $models): void;
}
