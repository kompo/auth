<?php

namespace Kompo\Auth\Models\Concerns\Security;

/**
 * Implements `HasProtectedFields` by reading legacy property declarations:
 *
 *   protected $sensibleColumns        = ['date_of_birth'];   // single group
 *   protected $sensibleColumnsGroups  = [                   // multiple groups
 *       'Person.medicalData' => ['medical_notes'],
 *       'Person.adminNotes'  => ['admin_notes'],
 *   ];
 *   protected $sensibleRelationships       = ['phones'];
 *   protected $sensibleRelationshipsGroups = [
 *       'Person.contactInfo' => ['emails', 'addresses'],
 *   ];
 *
 * The flat-array forms (`$sensibleColumns`, `$sensibleRelationships`) are
 * collapsed into a single group whose key is the permission key resolved from
 * the optional override properties:
 *
 *   protected $sensibleColumnsPermissionKey       = '...';   // optional
 *   protected $sensibleRelationshipsPermissionKey = '...';   // optional
 *
 * Defaults: `<PermissionKey>.sensibleColumns` and
 * `<PermissionKey>.sensibleRelationships`.
 *
 * Drop-in for migrating models like `Person` to `HasProtectedFields` without
 * rewriting their declarations.
 */
trait WithSimpleProtection
{
    public function getProtectionGroups(): array
    {
        $groups = [];

        $flatCols = getPrivateProperty($this, 'sensibleColumns') ?? [];
        if (!empty($flatCols)) {
            $groups[] = [
                'key'    => $this->resolveColumnsPermissionKey(),
                'fields' => array_values($flatCols),
                'type'   => 'columns',
            ];
        }

        $colGroups = getPrivateProperty($this, 'sensibleColumnsGroups') ?? [];
        foreach ($colGroups as $key => $fields) {
            $groups[] = [
                'key'    => (string) $key,
                'fields' => array_values($fields),
                'type'   => 'columns',
            ];
        }

        $flatRels = getPrivateProperty($this, 'sensibleRelationships') ?? [];
        if (!empty($flatRels)) {
            $groups[] = [
                'key'    => $this->resolveRelationshipsPermissionKey(),
                'fields' => array_values($flatRels),
                'type'   => 'relationships',
            ];
        }

        $relGroups = getPrivateProperty($this, 'sensibleRelationshipsGroups') ?? [];
        foreach ($relGroups as $key => $fields) {
            $groups[] = [
                'key'    => (string) $key,
                'fields' => array_values($fields),
                'type'   => 'relationships',
            ];
        }

        return $groups;
    }

    protected function resolveColumnsPermissionKey(): string
    {
        return (string) (
            getPrivateProperty($this, 'sensibleColumnsPermissionKey')
            ?? $this->basePermissionKeyForProtection() . '.sensibleColumns'
        );
    }

    protected function resolveRelationshipsPermissionKey(): string
    {
        return (string) (
            getPrivateProperty($this, 'sensibleRelationshipsPermissionKey')
            ?? $this->basePermissionKeyForProtection() . '.sensibleRelationships'
        );
    }

    protected function basePermissionKeyForProtection(): string
    {
        if ($this instanceof \Kompo\Auth\Contracts\Security\HasPermissionKey) {
            return $this->getPermissionKey();
        }

        if (method_exists($this, 'getPermissionKey')) {
            return $this->getPermissionKey();
        }

        $key = getPrivateProperty($this, 'permissionKey');

        return $key !== null ? (string) $key : class_basename(static::class);
    }
}
