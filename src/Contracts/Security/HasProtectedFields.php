<?php

namespace Kompo\Auth\Contracts\Security;

/**
 * Some columns / relationships on this model require a separate permission to
 * read. Replaces `$sensibleColumns`, `$sensibleColumnsGroups`,
 * `$sensibleRelationships`, `$sensibleRelationshipsGroups`,
 * `$sensibleColumnsPermissionKey`, `$sensibleRelationshipsPermissionKey`, and
 * `$discoverSensibleFromDb`.
 *
 * Absence of the contract → no field protection.
 *
 * Ready-made traits:
 *   - `Kompo\Auth\Models\Concerns\Security\WithSimpleProtection` — reads legacy
 *     `$sensibleColumns` / `$sensibleRelationships` properties.
 *   - `Kompo\Auth\Models\Concerns\Security\WithDbDiscoveredProtection` (TODO).
 */
interface HasProtectedFields
{
    /**
     * @return list<array{key: string, fields: list<string>, type: 'columns'|'relationships'}>
     */
    public function getProtectionGroups(): array;
}
