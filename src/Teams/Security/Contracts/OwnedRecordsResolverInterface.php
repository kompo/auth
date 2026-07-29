<?php

namespace Kompo\Auth\Teams\Security\Contracts;

use Kompo\Auth\Models\Teams\PermissionTypeEnum;

/**
 * Single source of truth for "which records of $modelClass does $userId own".
 *
 * Container-bound to the cached decorator; the pure resolver is available for
 * direct injection in isolation.
 *
 * The optional $type narrows the answer for models on `HasScopedOwnedRecords` —
 * a record can be owned for reading and not for writing. Models on the plain
 * `HasOwnedRecords` contract ignore it and answer the same for every verb.
 */
interface OwnedRecordsResolverInterface
{
    /**
     * @return array<int|string>
     */
    public function forUser(int $userId, string $modelClass, ?PermissionTypeEnum $type = null): array;

    public function isOwnedBy(int $userId, string $modelClass, $recordId, ?PermissionTypeEnum $type = null): bool;
}
