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
        if ($this->hasReadSecurityRestrictions() && permissionMustBeAuthorized($permissionKey)) {
            $this->modelClass::addGlobalScope('authUserHasPermissions', function ($builder) {
                if ($this->bypassService->isSecurityBypassRequired(new ($this->modelClass), $this->teamService)) {
                    // If security is bypassed, skip the read security scope
                    return $builder;
                }

                $this->applyReadSecurityScope($builder);
            });
        }
    }

    /**
     * Apply read security scope to query builder
     */
    protected function applyReadSecurityScope(Builder $builder): void
    {
        $hasUserOwnedRecordsScope = $this->modelHasMethod('scopeUserOwnedRecords');

        if (!$this->teamService->massRestrictByTeam()) {
            $this->applyNonTeamReadSecurity($builder, $hasUserOwnedRecordsScope);
        } else {
            $this->applyTeamReadSecurity($builder, $hasUserOwnedRecordsScope);
        }
    }

    /**
     * Apply non-team read security
     */
    protected function applyNonTeamReadSecurity(Builder $builder, bool $hasUserOwnedRecordsScope): void
    {
        $permissionKey = class_basename($this->modelClass);

        if (!auth()->user()?->hasPermission($permissionKey, PermissionTypeEnum::READ)) {
            $builder->when($hasUserOwnedRecordsScope, function ($q) {
                SecurityBypassService::enterBypassContext();
                $q->userOwnedRecords();
                SecurityBypassService::exitBypassContext();
            })->when(!$hasUserOwnedRecordsScope, function ($q) {
                if (hasColumnCached($this->getModelTable(), 'user_id')) {
                    $q->where($this->getModelTable() . '.user_id', auth()->user()?->id);
                }
            });
        }
    }

    /**
     * Apply team-based read security
     */
    protected function applyTeamReadSecurity(Builder $builder, bool $hasUserOwnedRecordsScope): void
    {
        $permissionKey = class_basename($this->modelClass);

        $builder->where(function ($q) use ($hasUserOwnedRecordsScope, $permissionKey) {
            // Check for new query-based security method first
            if ($this->modelHasMethod('scopeSecurityForTeamByQuery')) {
                $teamsQuery = auth()->user()?->getTeamsQueryWithPermission(
                    $permissionKey,
                    PermissionTypeEnum::READ,
                    $this->getModelTable()
                );
                if ($teamsQuery) {
                    $q->securityForTeamByQuery($teamsQuery);
                }
            } else if ($this->modelHasMethod('scopeSecurityForTeams')) {
                // Fallback to existing method with team IDs
                $teamIds = auth()->user()?->getTeamsIdsWithPermission(
                    $permissionKey,
                    PermissionTypeEnum::READ
                ) ?? [];
                $q->securityForTeams($teamIds);
            } else if ($teamIdCol = $this->teamService->getTeamIdColumn()) {
                $teamIds = auth()->user()?->getTeamsIdsWithPermission(
                    $permissionKey,
                    PermissionTypeEnum::READ
                ) ?? [];

                $q->whereIn($this->getModelTable() . '.' . $teamIdCol, $teamIds);
            }

            if ($hasUserOwnedRecordsScope) {
                $q->orWhere(function ($sq) {
                    SecurityBypassService::enterBypassContext();
                    $sq->userOwnedRecords();
                    SecurityBypassService::exitBypassContext();
                });
            } else {
                if (hasColumnCached($this->getModelTable(), 'user_id')) {
                    $q->orWhere($this->getModelTable() . '.user_id', auth()->user()?->id);
                }
            }
        });
    }
}
