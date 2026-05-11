<?php

namespace Kompo\Auth\Teams\Security;

/**
 * Per-query team-scope intent. Set by Builder macros, consumed by
 * `ReadSecurityService::getUserAuthorizedTeamIds`.
 *
 * Stack-based so nested queries (relation eager-loads, paginated counts)
 * can each carry their own intent without leaking to siblings.
 *
 * `current`     — narrow to `[currentTeamId()]` only.
 * `multi`       — expand to every team the user has the permission on.
 * `no-team`     — drop the team filter entirely (permission-gated only).
 *
 * When the stack is empty, the config default applies
 * (`kompo-auth.security.read.current_team` vs `.multi_team`).
 */
final class TeamScopeIntent
{
    /** @var list<'current'|'multi'|'no-team'> */
    private static array $stack = [];

    public static function pushCurrent(): void
    {
        self::$stack[] = 'current';
    }

    public static function pushMulti(): void
    {
        self::$stack[] = 'multi';
    }

    public static function pushNoTeam(): void
    {
        self::$stack[] = 'no-team';
    }

    /** Returns and consumes the top intent, or null when the stack is empty. */
    public static function consume(): ?string
    {
        return array_pop(self::$stack);
    }

    /** Peek at the top intent without consuming. */
    public static function peek(): ?string
    {
        return end(self::$stack) ?: null;
    }

    public static function reset(): void
    {
        self::$stack = [];
    }
}
