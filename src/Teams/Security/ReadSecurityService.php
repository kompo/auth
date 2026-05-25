<?php

namespace Kompo\Auth\Teams\Security;

use Condoedge\Utils\Contracts\Security\ScopedToTeam;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Teams\Security\Contracts\OwnedRecordsResolverInterface;
use Kompo\Auth\Teams\Security\Contracts\TeamSecurityServiceInterface;
use Kompo\Auth\Teams\Security\TeamScopeIntent;
use Kompo\Auth\Teams\Security\Traits\ModelHelperTrait;
use Kompo\Auth\Teams\Security\Traits\SecurityConfigTrait;
use Illuminate\Database\Eloquent\Builder;

/**
 * Handles read security scopes
 *
 * Responsibilities:
 * - Apply global read security scope to models
 * - Handle team-based read security
 * - Handle non-team read security
 * - Integrate with user-owned records scope
 */
class ReadSecurityService
{
    use ModelHelperTrait, SecurityConfigTrait;

    protected $modelClass;
    protected $bypassService;
    protected $teamService;
    protected $permissionKey;


    public function __construct(
        string $modelClass,
        SecurityBypassService $bypassService,
        TeamSecurityServiceInterface $teamService
    ) {
        $this->modelClass = $modelClass;
        $this->bypassService = $bypassService;
        $this->teamService = $teamService;
    }

    /**
     * `permissionMustBeAuthorized` runs inside the scope closure, not at
     * registration — at registration it forced a Permission lookup per booted
     * class, even ones never queried.
     */
    public function setupReadSecurity(string $permissionKey): void
    {
        $this->permissionKey = $permissionKey;

        if (!$this->hasReadSecurityRestrictions()) {
            return;
        }

        $this->modelClass::addGlobalScope('authUserHasPermissions', function ($builder) use ($permissionKey) {
            if ($this->shouldBypassSecurityForQuery()) {
                return $builder;
            }

            if (!permissionMustBeAuthorized($permissionKey)) {
                return $builder;
            }

            $this->applyReadSecurityScope($builder);
        });
    }

    /**
     * Check if security should be bypassed for this query.
     *
     * The previous implementation built `new ($this->modelClass)` per query
     * and passed it to `isSecurityBypassRequiredFast`. But on a freshly
     * constructed empty model the per-instance checks (`_bypassSecurity` flag,
     * `user_id` match) are always null/false and contribute no useful info at
     * scope-evaluation time. Per-record ownership is handled later by
     * `addOwnedRecordsAlternative` inside the scope body.
     *
     * What actually matters here is request-scope state: are we in a bypass
     * context, or is global bypass active for the request? Both are cheap
     * static reads with no model instantiation.
     */
    protected function shouldBypassSecurityForQuery(): bool
    {
        if (SecurityBypassService::isInBypassContext()) {
            return true;
        }

        return $this->bypassService->isGloballyBypassed();
    }

    /**
     * Apply read security scope to query builder
     */
    protected function applyReadSecurityScope(Builder $builder): void
    {
        if ($this->teamService->massRestrictByTeam($this->modelClass)) {
            $this->applyTeamBasedRestrictions($builder);
        } else {
            $this->applyNonTeamRestrictions($builder);
        }
    }

    /**
     * Apply restrictions for non-team-based models
     */
    protected function applyNonTeamRestrictions(Builder $builder): void
    {
        if ($this->userHasGlobalReadPermission()) {
            return;
        }

        $this->restrictToOwnedRecords($builder);
    }

    /**
     * Apply restrictions for team-based models
     */
    protected function applyTeamBasedRestrictions(Builder $builder): void
    {
        $builder->where(function ($q) {
            $this->applyTeamRestrictions($q);
            $this->orWhereOwned($q);
        });
    }

    /**
     * Apply team-based restrictions.
     *
     *   1. `ScopedToTeam` contract — preferred. Model owns the filter shape.
     *   2. Auto-detected `team_id` column — fallback for legacy/un-migrated
     *      models. Registry warns once per class.
     */
    protected function applyTeamRestrictions(Builder $query): void
    {
        $teamIds = $this->getUserAuthorizedTeamIds()->all();
        $model = $query->getModel();

        if ($model instanceof ScopedToTeam) {
            $model->applyTeamSecurityScope($query, $teamIds);
            return;
        }

        $autoColumn = SecurityMetadataRegistry::for($this->modelClass)['autoTeamIdColumn'];
        if ($autoColumn !== null) {
            $query->whereIn($this->getModelTable() . '.' . $autoColumn, $teamIds);
        }
    }

    /**
     * Team-path OR-clause: matches the team scope OR is in the user's owned-id set.
     * Ids come from `OwnedRecordsResolverInterface` (HasOwnedRecords contract).
     */
    protected function orWhereOwned(Builder $query): void
    {
        if ($this->teamService->shouldValidateOwnedRecords($query->getModel())) {
            return;
        }

        $ids = $this->ownedIds();
        if (empty($ids)) {
            return;
        }

        $query->orWhereIn($this->ownedIdColumn($query->getModel()), $ids);
    }

    /**
     * Non-team path: restrict to owned-id set, or to nothing if the user owns nothing
     * (preserves the "must own" semantics from the legacy direct-scope path).
     */
    protected function restrictToOwnedRecords(Builder $builder): void
    {
        if ($this->teamService->shouldValidateOwnedRecords($builder->getModel())) {
            return;
        }

        $ids = $this->ownedIds();
        if (empty($ids)) {
            $builder->whereRaw('1=0');
            return;
        }

        $builder->whereIn($this->ownedIdColumn($builder->getModel()), $ids);
    }

    protected function ownedIds(): array
    {
        $userId = auth()->id();
        if (!$userId) return [];

        return app(OwnedRecordsResolverInterface::class)->forUser($userId, $this->modelClass);
    }

    protected function ownedIdColumn($model): string
    {
        return $this->getModelTable() . '.' . $model->getKeyName();
    }

    /**
     * Check if current user has global read permission
     */
    protected function userHasGlobalReadPermission(): bool
    {
        return auth()->user()?->hasPermission(
            $this->permissionKey,
            PermissionTypeEnum::READ
        ) ?? false;
    }

    /**
     * Team IDs the user is authorized to read for this model.
     *
     * Resolution order:
     *   1. Per-query intent stack (`TeamScopeIntent`) — set by Builder macros.
     *      `current`  → `[currentTeamId()]` intersected with permitted ids.
     *      `multi`    → every team where the user holds the permission.
     *      `no-team`  → return empty (caller wants no filter; scope should skip).
     *   2. Config default — `security.read.current_team` and `security.read.multi_team`.
     *      `current_team = 'auto'` and `multi_team = 'auto'` → multi-team (back-compat).
     *      `current_team = 'auto'` and `multi_team = 'opt-in'` → current team only.
     *
     * Intent is consumed (popped) so each query carries its own.
     */
    protected function getUserAuthorizedTeamIds(): \Illuminate\Support\Collection
    {
        $user = auth()->user();
        if (!$user) {
            return collect();
        }

        $permittedTeamIds = $user->getTeamsIdsWithPermission(
            $this->permissionKey,
            PermissionTypeEnum::READ,
        ) ?? collect();

        $intent = TeamScopeIntent::consume();
        if ($intent === 'no-team') {
            return $permittedTeamIds;
        }
        if ($intent === 'multi') {
            return $permittedTeamIds;
        }
        if ($intent === 'current') {
            return $this->narrowToCurrentTeam($permittedTeamIds);
        }

        // No intent — fall back to config defaults.
        $currentTeam = kompoAuthSecurityConfig('read.current_team', 'auto');
        $multiTeam   = kompoAuthSecurityConfig('read.multi_team', 'auto');

        if ($currentTeam === 'auto' && $multiTeam !== 'auto') {
            return $this->narrowToCurrentTeam($permittedTeamIds);
        }

        return $permittedTeamIds;
    }

    protected function narrowToCurrentTeam(\Illuminate\Support\Collection $permittedTeamIds): \Illuminate\Support\Collection
    {
        $currentId = currentTeamId();
        if (!$currentId) {
            return collect();
        }

        $permitted = $permittedTeamIds->map(fn($id) => (int) $id);
        return $permitted->contains((int) $currentId)
            ? collect([(int) $currentId])
            : collect();
    }
}
