<?php

namespace Kompo\Auth\Models\Plugins\Services;

use Kompo\Auth\Models\Plugins\Services\Traits\SecurityConfigTrait;

/**
 * Handles delete security operations
 *
 * Responsibilities:
 * - Validate delete permissions before deleting
 * - Setup deleting event handlers
 * - Provide system delete functionality
 */
class DeleteSecurityService
{
    use SecurityConfigTrait;

    protected $modelClass;
    protected $bypassService;
    protected $teamService;
    protected $writeService;

    public function __construct(
        string $modelClass,
        SecurityBypassService $bypassService,
        TeamSecurityService $teamService,
        WriteSecurityService $writeService
    ) {
        $this->modelClass = $modelClass;
        $this->bypassService = $bypassService;
        $this->teamService = $teamService;
        $this->writeService = $writeService;
    }

    /**
     * Setup delete security event handler
     */
    public function setupDeleteSecurity(): void
    {
        $this->modelClass::deleting(function ($model) {
            $this->handleDeletingEvent($model);
        });
    }

    /**
     * Handle deleting event
     */
    protected function handleDeletingEvent($model): void
    {
        if ($this->bypassService->isSecurityBypassRequired($model, $this->teamService)) {
            $this->bypassService->markModelAsBypassed($model);
            return;
        }

        if ($this->hasDeleteSecurityRestrictions()) {
            // Delete uses same permission check as write
            $this->writeService->checkWritePermissions($model);
        }
    }

    /**
     * System delete (bypass security)
     */
    public function systemDelete($model): bool
    {
        $model->_bypassSecurity = true;
        $result = $model->delete();
        return $result;
    }
}
