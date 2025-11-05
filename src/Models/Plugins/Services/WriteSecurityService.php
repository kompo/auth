<?php

namespace Kompo\Auth\Models\Plugins\Services;

use Kompo\Auth\Models\Plugins\Services\Traits\SecurityConfigTrait;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\Roles\PermissionException;

/**
 * Handles write/save security operations
 *
 * Responsibilities:
 * - Validate write permissions before saving
 * - Handle team-based write permissions
 * - Setup saving event handlers
 */
class WriteSecurityService
{
    use SecurityConfigTrait;

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
     * Setup write security event handler
     */
    public function setupWriteSecurity(): void
    {
        $this->modelClass::saving(function ($model) {
            $this->handleSavingEvent($model);
        });
    }

    /**
     * Handle saving event
     */
    protected function handleSavingEvent($model): void
    {
        if ($this->shouldBypassSecurityForModel($model)) {
            $this->bypassService->markModelAsBypassed($model);
            return;
        }

        if ($this->shouldValidateWritePermissions()) {
            $this->checkWritePermissions($model);
        }
    }

    /**
     * Check if security should be bypassed for this model
     */
    protected function shouldBypassSecurityForModel($model): bool
    {
        return $this->bypassService->isSecurityBypassRequired($model, $this->teamService);
    }

    /**
     * Check if write permissions should be validated
     */
    protected function shouldValidateWritePermissions(): bool
    {
        return $this->hasSaveSecurityRestrictions();
    }

    /**
     * Check write permissions for model
     */
    public function checkWritePermissions($model = null): bool
    {
        if (!$this->isPermissionCheckRequired()) {
            return true;
        }

        if ($this->isTeamBasedModel($model)) {
            $this->validateTeamBasedWritePermission($model);
        } else {
            $this->validateGlobalWritePermission();
        }

        return true;
    }

    /**
     * Check if permission check is required
     */
    protected function isPermissionCheckRequired(): bool
    {
        return permissionMustBeAuthorized($this->getPermissionKey());
    }

    /**
     * Check if model uses team-based restrictions
     */
    protected function isTeamBasedModel($model): bool
    {
        return $this->teamService->individualRestrictByTeam($model);
    }

    /**
     * Validate global (non-team) write permission
     */
    protected function validateGlobalWritePermission(): void
    {
        if ($this->userHasGlobalWritePermission()) {
            return;
        }

        $this->throwWritePermissionException();
    }

    /**
     * Validate team-based write permission
     */
    protected function validateTeamBasedWritePermission($model): void
    {
        $teamIds = $this->getModelTeamIds($model);

        if ($this->userHasWritePermissionForTeams($teamIds)) {
            return;
        }

        $this->throwWritePermissionException($teamIds);
    }

    /**
     * Check if user has global write permission
     */
    protected function userHasGlobalWritePermission(): bool
    {
        return auth()->user()?->hasPermission(
            $this->getPermissionKey(),
            PermissionTypeEnum::WRITE
        ) ?? false;
    }

    /**
     * Check if user has write permission for specific teams
     */
    protected function userHasWritePermissionForTeams($teamIds): bool
    {
        return auth()->user()?->hasPermission(
            $this->getPermissionKey(),
            PermissionTypeEnum::WRITE,
            $teamIds
        ) ?? false;
    }

    /**
     * Get team IDs for the model
     */
    protected function getModelTeamIds($model)
    {
        return $this->teamService->getTeamOwnersIdsSafe($model);
    }

    /**
     * Throw write permission exception
     */
    protected function throwWritePermissionException($teamIds = []): void
    {
        throw new PermissionException(
            __('permissions-you-do-not-have-write-permissions'),
            $this->getPermissionKey(),
            PermissionTypeEnum::WRITE,
            $teamIds
        );
    }

    /**
     * Get permission key for this model
     */
    protected function getPermissionKey(): string
    {
        return class_basename($this->modelClass);
    }

    /**
     * System save (bypass security)
     */
    public function systemSave($model): bool
    {
        $this->markModelForBypass($model);
        return $this->performSave($model);
    }

    /**
     * Mark model to bypass security
     */
    protected function markModelForBypass($model): void
    {
        $model->_bypassSecurity = true;
    }

    /**
     * Perform the actual save operation
     */
    protected function performSave($model): bool
    {
        $model->save();

        return true;
    }
}
