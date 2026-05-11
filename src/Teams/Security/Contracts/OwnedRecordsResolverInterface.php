<?php

namespace Kompo\Auth\Teams\Security\Contracts;

/**
 * Single source of truth for "which records of $modelClass does $userId own".
 *
 * Container-bound to the cached decorator; the pure resolver is available for
 * direct injection in isolation.
 */
interface OwnedRecordsResolverInterface
{
    /**
     * @return array<int|string>
     */
    public function forUser(int $userId, string $modelClass): array;

    public function isOwnedBy(int $userId, string $modelClass, $recordId): bool;
}
