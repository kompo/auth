<?php

namespace Kompo\Auth\Teams\Cache;

use Kompo\Auth\Teams\Security\Contracts\OwnedRecordsResolverInterface;

/**
 * Per-request memo over OwnedRecordsResolver, keyed by (modelClass, userId).
 * Flushed at request end + on save/delete of any model class with owned-record
 * semantics (HasSecurity wires this).
 */
class CachedOwnedRecordsResolver implements OwnedRecordsResolverInterface
{
    /** @var array<string, array<int, array<int|string>>> */
    protected static array $cache = [];

    public function __construct(
        protected OwnedRecordsResolverInterface $inner,
    ) {}

    public function forUser(int $userId, string $modelClass): array
    {
        if (isset(static::$cache[$modelClass][$userId])) {
            return static::$cache[$modelClass][$userId];
        }

        return static::$cache[$modelClass][$userId] = $this->inner->forUser($userId, $modelClass);
    }

    public function isOwnedBy(int $userId, string $modelClass, $recordId): bool
    {
        return in_array($recordId, $this->forUser($userId, $modelClass), false);
    }

    public static function flush(): void
    {
        static::$cache = [];
    }

    public static function flushFor(string $modelClass, ?int $userId = null): void
    {
        if ($userId === null) {
            unset(static::$cache[$modelClass]);
            return;
        }
        unset(static::$cache[$modelClass][$userId]);
    }
}
