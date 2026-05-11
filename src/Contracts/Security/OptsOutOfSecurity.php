<?php

namespace Kompo\Auth\Contracts\Security;

/**
 * The model explicitly opts out of HasSecurity for the listed operations.
 * Replaces `$readSecurityRestrictions = false`, `$saveSecurityRestrictions = false`,
 * `$deleteSecurityRestrictions = false`.
 *
 * Absence of the contract → all three operations are gated by HasSecurity
 * defaults / config.
 */
interface OptsOutOfSecurity
{
    /**
     * @return list<'read'|'write'|'delete'>
     */
    public function getSkippedSecurityOperations(): array;
}
