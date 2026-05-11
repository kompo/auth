<?php

namespace Kompo\Auth\Teams\Cache;

/**
 * Per-request cache for the static portion of globalSecurityBypass().
 *
 * Why this exists:
 *   globalSecurityBypass() is called many times per request (every Kompo
 *   element boot, every permission check, every protection group). Inside
 *   the closure, routeIsByPassed() runs a full Laravel router match for
 *   Kompo AJAX requests — expensive when repeated 75k–150k times.
 *
 * What is memoized here:
 *   The "static" portion only — checks that depend on the request, the
 *   authenticated user identity, and config: runningInConsole, session,
 *   isSuperAdmin, routeIsByPassed, security.bypass.global.
 *
 * What is NOT memoized:
 *   isInBypassContext() — that toggles mid-request as code enters/exits
 *   ownership-detection blocks. The composed binding evaluates it live on
 *   every call so the toggle stays responsive.
 *
 * Cache lifetime:
 *   The cache lives for the duration of one request and is flushed by:
 *     - app()->terminating() in KompoAuthServiceProvider
 *     - Login / Logout / impersonation events
 *
 * Layering:
 *   This class is a pure cache layer. The "what to compute" lives in the
 *   `kompo-auth.security-bypass.static` singleton binding. This class
 *   doesn't know what the resolver does — it only memoizes the boolean.
 */
class GlobalSecurityBypassCache
{
    private static ?bool $cached = null;

    /**
     * Return the cached static-bypass value, computing it on first call.
     *
     * The resolver receives no arguments and returns a boolean.
     */
    public static function resolve(callable $resolver): bool
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        return self::$cached = (bool) $resolver();
    }

    /**
     * Forget the cached value so the next call recomputes.
     */
    public static function flush(): void
    {
        self::$cached = null;
    }

    /**
     * For diagnostics / tests only.
     */
    public static function isCached(): bool
    {
        return self::$cached !== null;
    }
}
