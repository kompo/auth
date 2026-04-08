<?php

namespace Kompo\Auth\Models\Plugins\Services;

/**
 * Centralized debug logger for the security plugin system.
 * Controlled by config('kompo-auth.debug-security').
 * Off by default — no overhead in production.
 */
class SecurityDebug
{
    protected static ?bool $enabled = null;

    public static function log(string $channel, string $message, array $context = []): void
    {
        if (static::$enabled === null) {
            static::$enabled = (bool) config('kompo-auth.debug-security', false);
        }

        if (static::$enabled) {
            \Log::debug("[SECURITY:{$channel}] {$message}", $context);
        }
    }

    public static function reset(): void
    {
        static::$enabled = null;
    }
}
