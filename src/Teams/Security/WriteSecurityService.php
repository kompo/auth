<?php

namespace Kompo\Auth\Teams\Security;

use Kompo\Auth\Teams\Security\Contracts\FieldProtectionServiceInterface;
use Kompo\Auth\Teams\Security\Contracts\TeamSecurityServiceInterface;
use Kompo\Auth\Teams\Security\SecurityMetadataRegistry;
use Kompo\Auth\Teams\Security\Traits\SecurityConfigTrait;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\Roles\InsufficientFieldPermissionException;
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
     * Setup write security event handler
     */
    public function setupWriteSecurity(string $permissionKey): void
    {
        $this->permissionKey = $permissionKey;
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
            $this->validateDirtyProtectedFields($model);
        }
    }

    /**
     * For every dirty column that's part of a `HasProtectedFields` group on
     * this model, require the user to have the group's permission. Strict AND
     * across groups: a column in two groups needs both. Throws
     * `InsufficientFieldPermissionException` on first failure; the `saving`
     * listener aborts the whole save (Eloquent rolls back).
     *
     * Inserts are skipped unless `kompo-auth.security.fields.gate_inserts`
     * is true — a fresh row is "all dirty" by definition and gating it would
     * mostly fire on Person creation paths where the general write permission
     * already gates the operation.
     */
    protected function validateDirtyProtectedFields($model): void
    {
        $meta = SecurityMetadataRegistry::for(get_class($model));
        if (empty($meta['protectedColumns'])) {
            return;
        }

        if (!$model->exists && !kompoAuthSecurityConfig('fields.gate_inserts', false)) {
            return;
        }

        $dirtyKeys = array_keys($model->getDirty());
        $dirtyProtected = array_intersect($dirtyKeys, array_keys($meta['protectedColumns']));
        if (empty($dirtyProtected)) {
            return;
        }

        $fieldProtection = app(FieldProtectionServiceInterface::class);

        foreach ($dirtyProtected as $column) {
            foreach ($meta['groups'] as $group) {
                if ($group['type'] !== 'columns' || !in_array($column, $group['fields'], true)) {
                    continue;
                }
                if (!$fieldProtection->hasPermissionForProtectionKey($model, $group['key'])) {
                    throw new InsufficientFieldPermissionException(
                        get_class($model),
                        $column,
                        $group['key'],
                    );
                }
            }
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
        return permissionMustBeAuthorized($this->permissionKey);
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
            $this->permissionKey,
            PermissionTypeEnum::WRITE
        ) ?? false;
    }

    /**
     * Check if user has write permission for specific teams
     */
    protected function userHasWritePermissionForTeams($teamIds): bool
    {
        return auth()->user()?->hasPermission(
            $this->permissionKey,
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
        // This is the last fallback so the idea is that never the user reaches this point
        // since they shouldn't have seen the button for example or the place to execute action
        // Logging it we can track if there are any edge cases where the permission checks are not working as expected and users are trying to perform unauthorized actions
        \Log::warning('Write permission denied', [
            'user_id' => auth()->id(),
            'permission_key' => $this->permissionKey,
            'team_ids' => $teamIds,
            'current_route' => request()->route()->getName(),
            'debug_backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30),
        ]);

        throw new PermissionException(
            __('permissions-you-do-not-have-write-permissions'),
            $this->permissionKey,
            PermissionTypeEnum::WRITE,
            $teamIds
        );
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
