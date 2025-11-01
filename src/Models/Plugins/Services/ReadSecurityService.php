<?php

namespace Kompo\Auth\Models\Plugins\Services;

use Kompo\Auth\Models\Plugins\Services\Traits\ModelHelperTrait;
use Kompo\Auth\Models\Plugins\Services\Traits\SecurityConfigTrait;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
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

    public function __construct(
        string $modelClass,
        SecurityBypassService $bypassService,
        TeamSecurityService $teamService
    ) {
        $this->modelClass = $modelClass;
        $this->bypassService = $bypassService;
        $this->teamService = $teamService;
    }

    /**
     * Setup read security global scope
     */
    public function setupReadSecurity(string $permissionKey): void
    {
        if (!$this->shouldApplyReadSecurity($permissionKey)) {
            return;
        }

        $this->modelClass::addGlobalScope('authUserHasPermissions', function ($builder) {
            if ($this->shouldBypassSecurityForQuery()) {
                return $builder;
            }

            $this->applyReadSecurityScope($builder);
        });
    }

    /**
     * Check if read security should be applied
     */
    protected function shouldApplyReadSecurity(string $permissionKey): bool
    {
        return $this->hasReadSecurityRestrictions() && permissionMustBeAuthorized($permissionKey);
    }

    /**
     * Check if security should be bypassed for this query
     */
    protected function shouldBypassSecurityForQuery(): bool
    {
        return $this->bypassService->isSecurityBypassRequired(
            new ($this->modelClass),
            $this->teamService
        );
    }

    /**
     * Apply read security scope to query builder
     */
    protected function applyReadSecurityScope(Builder $builder): void
    {
        $hasUserOwnedRecordsScope = $this->modelHasMethod('scopeUserOwnedRecords');

        if ($this->teamService->massRestrictByTeam()) {
            $this->applyTeamBasedRestrictions($builder, $hasUserOwnedRecordsScope);
        } else {
            $this->applyNonTeamRestrictions($builder, $hasUserOwnedRecordsScope);
        }
    }

    /**
     * Apply restrictions for non-team-based models
     */
    protected function applyNonTeamRestrictions(Builder $builder, bool $hasUserOwnedRecordsScope): void
    {
        if ($this->userHasGlobalReadPermission()) {
            return; // User has global permission, no restrictions needed
        }

        // User doesn't have global permission, restrict to owned records
        $this->restrictToOwnedRecords($builder, $hasUserOwnedRecordsScope);
    }

    /**
     * Apply restrictions for team-based models
     */
    protected function applyTeamBasedRestrictions(Builder $builder, bool $hasUserOwnedRecordsScope): void
    {
        $builder->where(function ($q) use ($hasUserOwnedRecordsScope) {
            // Apply team restrictions
            $this->applyTeamRestrictions($q);

            // Add OR condition for owned records
            $this->addOwnedRecordsAlternative($q, $hasUserOwnedRecordsScope);
        });
    }

    /**
     * Apply team-based restrictions using available strategy
     */
    protected function applyTeamRestrictions(Builder $query): void
    {
        // Strategy 1: Query-based security (most flexible)
        if ($this->applyQueryBasedTeamSecurity($query)) {
            return;
        }

        // Strategy 2: Scope-based security (custom implementation)
        if ($this->applyScopeBasedTeamSecurity($query)) {
            return;
        }

        // Strategy 3: Column-based security (simple WHERE IN)
        $this->applyColumnBasedTeamSecurity($query);
    }

    /**
     * Strategy 1: Apply query-based team security
     */
    protected function applyQueryBasedTeamSecurity(Builder $query): bool
    {
        if (!$this->modelHasMethod('scopeSecurityForTeamByQuery')) {
            return false;
        }

        $teamsQuery = $this->getUserTeamsQuery();
        if (!$teamsQuery) {
            return false;
        }

        $query->securityForTeamByQuery($teamsQuery);
        return true;
    }

    /**
     * Strategy 2: Apply scope-based team security
     */
    protected function applyScopeBasedTeamSecurity(Builder $query): bool
    {
        if (!$this->modelHasMethod('scopeSecurityForTeams')) {
            return false;
        }

        $teamIds = $this->getUserAuthorizedTeamIds();
        $query->securityForTeams($teamIds);
        return true;
    }

    /**
     * Strategy 3: Apply column-based team security
     */
    protected function applyColumnBasedTeamSecurity(Builder $query): void
    {
        $teamIdColumn = $this->teamService->getTeamIdColumn();
        if (!$teamIdColumn) {
            return;
        }

        $teamIds = $this->getUserAuthorizedTeamIds();
        $query->whereIn($this->getModelTable() . '.' . $teamIdColumn, $teamIds);
    }

    /**
     * Add owned records as alternative to team restrictions
     */
    protected function addOwnedRecordsAlternative(Builder $query, bool $hasUserOwnedRecordsScope): void
    {
        if ($hasUserOwnedRecordsScope) {
            $this->applyUserOwnedRecordsScope($query);
        } else {
            $this->applyUserIdFallback($query);
        }
    }

    /**
     * Restrict query to user-owned records only
     */
    protected function restrictToOwnedRecords(Builder $builder, bool $hasUserOwnedRecordsScope): void
    {
        if ($hasUserOwnedRecordsScope) {
            $this->applyUserOwnedRecordsScopeDirectly($builder);
        } else {
            $this->applyUserIdRestriction($builder);
        }
    }

    /**
     * Apply user-owned records scope with OR condition (for team-based)
     */
    protected function applyUserOwnedRecordsScope(Builder $query): void
    {
        $query->orWhere(function ($sq) {
            SecurityBypassService::enterBypassContext();
            $sq->userOwnedRecords();
            SecurityBypassService::exitBypassContext();
        });
    }

    /**
     * Apply user-owned records scope directly (for non-team)
     */
    protected function applyUserOwnedRecordsScopeDirectly(Builder $builder): void
    {
        SecurityBypassService::enterBypassContext();
        $builder->userOwnedRecords();
        SecurityBypassService::exitBypassContext();
    }

    /**
     * Apply user_id restriction as fallback (OR condition for team-based)
     */
    protected function applyUserIdFallback(Builder $query): void
    {
        if (hasColumnCached($this->getModelTable(), 'user_id')) {
            $query->orWhere($this->getModelTable() . '.user_id', auth()->user()?->id);
        }
    }

    /**
     * Apply user_id restriction directly (for non-team)
     */
    protected function applyUserIdRestriction(Builder $builder): void
    {
        if (hasColumnCached($this->getModelTable(), 'user_id')) {
            $builder->where($this->getModelTable() . '.user_id', auth()->user()?->id);
        }
    }

    /**
     * Check if current user has global read permission
     */
    protected function userHasGlobalReadPermission(): bool
    {
        return auth()->user()?->hasPermission(
            $this->getPermissionKey(),
            PermissionTypeEnum::READ
        ) ?? false;
    }

    /**
     * Get teams query for current user with read permission
     */
    protected function getUserTeamsQuery()
    {
        return auth()->user()?->getTeamsQueryWithPermission(
            $this->getPermissionKey(),
            PermissionTypeEnum::READ,
            $this->getModelTable()
        );
    }

    /**
     * Get team IDs where user has read permission
     */
    protected function getUserAuthorizedTeamIds(): array
    {
        return auth()->user()?->getTeamsIdsWithPermission(
            $this->getPermissionKey(),
            PermissionTypeEnum::READ
        ) ?? [];
    }

    /**
     * Get permission key for this model
     */
    protected function getPermissionKey(): string
    {
        return class_basename($this->modelClass);
    }
}
