<?php

namespace Kompo\Auth\Contracts\Security;

/**
 * Marker interface — the model trusts only permissions. Ownership (user_id
 * match, owned-records resolver, owner bypass) grants nothing.
 *
 * Replaces `$disableOwnerBypass` (user_id shortcut only) and
 * `$validateOwnedAsWell` (strict superset) — both expressed the same intent.
 *
 * No methods. Presence of the interface is the signal.
 */
interface EnforcesStrictPermissions
{
}
