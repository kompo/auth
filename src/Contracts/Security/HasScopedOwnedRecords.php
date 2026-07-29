<?php

namespace Kompo\Auth\Contracts\Security;

use Kompo\Auth\Models\Teams\PermissionTypeEnum;

/**
 * Ownership that differs by verb.
 *
 * `HasOwnedRecords` answers one question — "which records are this user's?" — and
 * the bypass layer turns that single answer into a full pass: read, field
 * protection, write and delete. That is right for a record the user simply owns,
 * and wrong for a record the user only reaches *through* something they own.
 *
 * A contact linked to your own person is the canonical case: it is yours to read
 * — that is what a contact list is — but writing it is a different question,
 * because the moment the contact holds a user account their name and email are
 * their own identity, not your data.
 *
 * Implement this instead of `HasOwnedRecords` when the two sets differ. The
 * resolver passes the verb being authorized; `null` means "no verb in mind" and
 * must return the READ set, so existing callers keep working unchanged.
 *
 * Implementations MUST be safe to call from inside a bypass context — the
 * resolver toggles bypass around them to avoid recursion.
 */
interface HasScopedOwnedRecords extends HasOwnedRecords
{
    /**
     * @return array<int|string> Primary key values $userId owns for $type.
     */
    public function ownedRecordIdsForUser(int $userId, ?PermissionTypeEnum $type = null): array;
}
