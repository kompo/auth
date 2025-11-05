<?php

namespace Kompo\Auth\Models\Plugins\Services;

use Illuminate\Support\Facades\Log;

/**
 * Handles all security bypass logic and tracking
 *
 * Responsibilities:
 * - Track models that have bypassed security
 * - Manage bypass context to prevent infinite loops
 * - Evaluate various bypass strategies (flag, userId, scope, allowlist, custom)
 */
class SecurityBypassService
{
    /**
     * Tracks models that have bypassed security for the current request.
     */
    protected static $bypassedModels = [];

    /**
     * Tracks when we're in a security bypass context (like usersIdsAllowedToManage)
     * When true, all security checks are bypassed to prevent infinite loops
     */
    protected static $inBypassContext = false;

    protected static $inBypassContextTrace = [];

    /**
     * Tracks models that were booted during bypass context and need rebooting
     */
    protected static $modelsBootedDuringBypass = [];

    /**
     * Check if security is globally bypassed
     */
    public function isGloballyBypassed(): bool
    {
        return globalSecurityBypass();
    }

    /**
     * Check if bypass context is active
     */
    public static function isInBypassContext(): bool
    {
        return static::$inBypassContext;
    }

    /**
     * Enter bypass context
     */
    public static function enterBypassContext(): void
    {
        static::$inBypassContext = true;
        static::$inBypassContextTrace[] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    }

    /**
     * Exit bypass context
     */
    public static function exitBypassContext(): void
    {
        static::$inBypassContext = false;
        static::$inBypassContextTrace = [];

        // Reboot models that were booted during bypass context
        foreach (static::$modelsBootedDuringBypass as $modelClass => $true) {
            $modelClass::boot();
        }

        static::$modelsBootedDuringBypass = [];

        static::clearTracking();
    }

    /**
     * Mark a model as bypassed
     */
    public function markModelAsBypassed($model): void
    {
        $model->offsetUnset('_bypassSecurity');
        static::$bypassedModels[spl_object_hash($model)] = true;
    }

    /**
     * Track that a model was booted during bypass context
     */
    public static function trackModelBootedDuringBypass(string $modelClass): void
    {
        if (static::$inBypassContext) {
            static::$modelsBootedDuringBypass[$modelClass] = true;
        }
    }

    /**
     * Enhanced security bypass check with bypass context
     */
    public function isSecurityBypassRequired($model, TeamSecurityService $teamService): bool
    {
        // If we're in bypass context, always bypass
        if ($this->isGloballyBypassed()) {
            return true;
        }

        // Check simple flags first (no database queries)
        if ($this->hasBypassByFlag($model)) {
            return true;
        }

        if ($this->hasBypassByUserId($model, $teamService)) {
            return true;
        }

        // Check custom method
        if ($this->hasBypassMethod($model)) {
            return true;
        }

        // Enter bypass context for methods that might query related models
        static::enterBypassContext();

        try {
            // Check allowlist (potential recursion risk)
            if ($this->hasBypassByAllowlist($model)) {
                return true;
            }

            // Check scope (potential recursion risk)
            if ($this->hasBypassByScope($model, $teamService)) {
                return true;
            }

            return false;
        } finally {
            // Always exit bypass context
            static::exitBypassContext();
        }
    }

    /**
     * Check if bypass by flag applies
     */
    protected function hasBypassByFlag($model): bool
    {
        return $model->getAttribute('_bypassSecurity') == true ||
            (static::$bypassedModels[spl_object_hash($model)] ?? false);
    }

    /**
     * Check if bypass by user ID match applies
     */
    protected function hasBypassByUserId($model, TeamSecurityService $teamService): bool
    {
        // Check if owner validation is enforced
        if ($teamService->shouldValidateOwnedRecords($model)) {
            return false;
        }

        if ($model->getAttribute('user_id') && auth()->user() && !getPrivateProperty($model, 'disableOwnerBypass')) {
            return $model->getAttribute('user_id') === auth()->user()->id;
        }

        return false;
    }

    /**
     * Check if model has custom bypass method
     */
    protected function hasBypassMethod($model): bool
    {
        if (method_exists($model, 'isSecurityBypassRequired')) {
            try {
                return $model->securityHasBeenBypassed();
            } catch (\Throwable $e) {
                Log::warning('Custom bypass method failed', [
                    'model_class' => get_class($model),
                    'error' => $e->getMessage()
                ]);
                return false;
            }
        }

        return false;
    }

    /**
     * Check bypass by allowlist method
     */
    protected function hasBypassByAllowlist($model): bool
    {
        if (!method_exists($model, 'usersIdsAllowedToManage') || !auth()->user()) {
            return false;
        }

        try {
            static::enterBypassContext();
            $allowedUserIds = $model->usersIdsAllowedToManage();
            static::exitBypassContext();

            return collect($allowedUserIds)->contains(auth()->user()->id);
        } catch (\Throwable $e) {
            Log::warning('usersIdsAllowedToManage check failed', [
                'model_class' => get_class($model),
                'model_id' => $model->getKey(),
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check bypass by scope method
     */
    protected function hasBypassByScope($model, TeamSecurityService $teamService): bool
    {
        // Check if owner validation is enforced
        if ($teamService->shouldValidateOwnedRecords($model)) {
            return false;
        }

        if (!method_exists($model, 'scopeUserOwnedRecords') || !auth()->user()) {
            return false;
        }

        try {
            static::enterBypassContext();
            $hasByPassMethod = $model->userOwnedRecords()->where($model->getKeyName(), $model->getKey())->exists();
            static::exitBypassContext();
            return $hasByPassMethod;

        } catch (\Throwable $e) {
            Log::warning('scopeUserOwnedRecords check failed', [
                'model_class' => get_class($model),
                'model_id' => $model->getKey(),
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Clear all bypass tracking
     */
    public static function clearTracking(): void
    {
        static::$bypassedModels = [];
        static::$modelsBootedDuringBypass = [];
    }

    /**
     * Get bypassed models count
     */
    public static function getBypassedCount(): int
    {
        return count(static::$bypassedModels);
    }

    /**
     * Clean up bypass tracking for a specific model
     */
    public function cleanupModelBypass($model): void
    {
        $objectHash = spl_object_hash($model);
        unset(static::$bypassedModels[$objectHash]);
    }
}
