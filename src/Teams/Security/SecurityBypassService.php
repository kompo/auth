<?php

namespace Kompo\Auth\Teams\Security;

use Condoedge\Utils\Contracts\Security\HasOwnedRecords;
use Kompo\Auth\Teams\Security\Contracts\OwnedRecordsResolverInterface;
use Kompo\Auth\Teams\Security\Contracts\TeamSecurityServiceInterface;
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
     * Reference-counted bypass depth.
     *
     * Previously a boolean flag — nesting was unsafe: any inner caller that
     * paired its own enter/exit would clobber the outer context. The counter
     * makes nesting safe: each `enterBypassContext()` increments, each
     * `exitBypassContext()` decrements, and cleanup (model reboot, tracking
     * clear) only fires when the depth drops back to zero.
     */
    protected static int $bypassDepth = 0;

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
     * Check if bypass context is active (depth > 0).
     */
    public static function isInBypassContext(): bool
    {
        return static::$bypassDepth > 0;
    }

    /**
     * Enter bypass context. Safe to nest — each call must be paired with an
     * `exitBypassContext()`.
     */
    public static function enterBypassContext(): void
    {
        static::$bypassDepth++;
    }

    /**
     * Exit bypass context. Decrements the depth; cleanup (model reboot,
     * tracking clear) runs only when the depth reaches zero. Underflow is
     * floored at 0 so an over-call can't put us into a negative state.
     */
    public static function exitBypassContext(): void
    {
        if (static::$bypassDepth > 0) {
            static::$bypassDepth--;
        }

        if (static::$bypassDepth > 0) {
            // Still nested inside an outer bypass — defer cleanup.
            return;
        }

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
        if (static::isInBypassContext()) {
            static::$modelsBootedDuringBypass[$modelClass] = true;
        }
    }

    /**
     * Fast bypass check for the READ side (field protection, lazy resolution,
     * batch bypass). Honors both the full `_bypassSecurity` flag and the
     * read-only `_bypassReadSecurity` flag. O(1), no DB.
     */
    public function isSecurityBypassRequiredFast($model, TeamSecurityServiceInterface $teamService): bool
    {
        if ($this->isGloballyBypassed()) {
            return true;
        }

        if ($this->hasBypassByReadFlag($model)) {
            return true;
        }

        if ($this->hasBypassByUserId($model, $teamService)) {
            return true;
        }

        if ($this->hasBypassMethod($model)) {
            return true;
        }

        return false;
    }

    /**
     * Full bypass check. The owned-record check (hasBypassByScope) routes
     * through OwnedRecordsResolverInterface, which manages its own bypass
     * context internally — no enter/exit wrapper needed here anymore.
     */
    public function isSecurityBypassRequired($model, TeamSecurityServiceInterface $teamService): bool
    {
        if ($this->isGloballyBypassed()) {
            return true;
        }

        if ($this->hasBypassByFlag($model)) {
            return true;
        }

        if ($this->hasBypassByUserId($model, $teamService)) {
            return true;
        }

        if ($this->hasBypassMethod($model)) {
            return true;
        }

        return $this->hasBypassByScope($model, $teamService);
    }

    /**
     * Full-bypass flag — set by `asSystemOperation()` and by explicit
     * `markModelAsBypassed()` calls. Honored by write/delete paths.
     */
    protected function hasBypassByFlag($model): bool
    {
        return $model->getAttribute('_bypassSecurity') == true
            || (static::$bypassedModels[spl_object_hash($model)] ?? false);
    }

    /**
     * Either flag — used by the read-side fast path. The read-only macros
     * (e.g. `alreadyVerifiedAccess`, `withReadOnlyBypass`) emit
     * `_bypassReadSecurity`; full-bypass paths emit `_bypassSecurity`. Read
     * security treats both the same; write/delete only honor the full flag.
     */
    protected function hasBypassByReadFlag($model): bool
    {
        return $this->hasBypassByFlag($model)
            || $model->getAttribute('_bypassReadSecurity') == true;
    }

    /**
     * Bypass when the model's `user_id` matches the auth user. Gated by
     * `shouldValidateOwnedRecords` — `EnforcesStrictPermissions` short-circuits
     * this off without needing the legacy `$disableOwnerBypass` property.
     */
    protected function hasBypassByUserId($model, TeamSecurityServiceInterface $teamService): bool
    {
        if ($teamService->shouldValidateOwnedRecords($model)) {
            return false;
        }

        if ($model->getAttribute('user_id') && auth()->user()) {
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
     * Bypass via owned-records resolver. O(1) check against the cached id set
     * produced by the model's `HasOwnedRecords` contract.
     */
    protected function hasBypassByScope($model, TeamSecurityServiceInterface $teamService): bool
    {
        if ($teamService->shouldValidateOwnedRecords($model)) {
            return false;
        }

        $userId = auth()->id();
        if (!$userId) {
            return false;
        }

        if (!is_subclass_of(get_class($model), HasOwnedRecords::class)) {
            return false;
        }

        return app(OwnedRecordsResolverInterface::class)
            ->isOwnedBy($userId, get_class($model), $model->getKey());
    }

    /**
     * Clear all bypass tracking. Resets the depth counter too — used by the
     * request-lifecycle terminator so a leaked enter (missing exit) from one
     * request can't bleed into the next.
     */
    public static function clearTracking(): void
    {
        static::$bypassedModels = [];
        static::$modelsBootedDuringBypass = [];
        static::$bypassDepth = 0;
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
