<?php

namespace Kompo\Auth\Teams\Cache;

use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Teams\Security\Contracts\OwnedRecordsResolverInterface;

/**
 * Per-request memo over OwnedRecordsResolver, keyed by (modelClass, userId, type).
 * The type is part of the key: a scoped model answers READ and WRITE differently,
 * and caching one under the other's key would hand a write pass to a reader.
 * Flushed at request end + on save/delete of any model class with owned-record
 * semantics (HasSecurity wires this).
 */
class CachedOwnedRecordsResolver implements OwnedRecordsResolverInterface
{
    /** @var array<string, array<int, array<string, array<int|string>>>> */
    protected static array $cache = [];

    public function __construct(
        protected OwnedRecordsResolverInterface $inner,
    ) {}

    public function forUser(int $userId, string $modelClass, ?PermissionTypeEnum $type = null): array
    {
        $typeKey = $type?->name ?? 'any';

        if (isset(static::$cache[$modelClass][$userId][$typeKey])) {
            return static::$cache[$modelClass][$userId][$typeKey];
        }

        return static::$cache[$modelClass][$userId][$typeKey] = $this->inner->forUser($userId, $modelClass, $type);
    }

    public function isOwnedBy(int $userId, string $modelClass, $recordId, ?PermissionTypeEnum $type = null): bool
    {
        return in_array($recordId, $this->forUser($userId, $modelClass, $type), false);
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
