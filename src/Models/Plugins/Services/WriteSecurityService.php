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
        if ($this->bypassService->isSecurityBypassRequired($model, $this->teamService)) {
            $this->bypassService->markModelAsBypassed($model);
            return;
        }

        if ($this->hasSaveSecurityRestrictions()) {
            $this->checkWritePermissions($model);
        }
    }

    /**
     * Check write permissions
     */
    public function checkWritePermissions($model = null): bool
    {
        $permissionKey = class_basename($this->modelClass);

        if (!permissionMustBeAuthorized($permissionKey)) {
            return true;
        }

        if (
            !$this->teamService->individualRestrictByTeam($model) &&
            !auth()->user()?->hasPermission($permissionKey, PermissionTypeEnum::WRITE)
        ) {
            throw new PermissionException(
                __('permissions-you-do-not-have-write-permissions'),
                $permissionKey,
                PermissionTypeEnum::WRITE,
                []
            );
        }

        if (
            $this->teamService->individualRestrictByTeam($model) &&
            !auth()->user()?->hasPermission(
                $permissionKey,
                PermissionTypeEnum::WRITE,
                $this->teamService->getTeamOwnersIdsSafe($model)
            )
        ) {
            throw new PermissionException(
                __('permissions-you-do-not-have-write-permissions'),
                $permissionKey,
                PermissionTypeEnum::WRITE,
                $this->teamService->getTeamOwnersIdsSafe($model)
            );
        }

        return true;
    }

    /**
     * System save (bypass security)
     */
    public function systemSave($model): bool
    {
        $model->_bypassSecurity = true;
        $result = $model->save();
        return $result;
    }
}
