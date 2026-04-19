<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Models\Teams\PermissionTypeEnum;

final class PermissionAccessIndex
{
    private array $allowMembers;
    private array $denyMembers;

    public function __construct(array $allowMembers = [], array $denyMembers = [])
    {
        $this->allowMembers = $this->normalizeMembers($allowMembers);
        $this->denyMembers = $this->normalizeMembers($denyMembers);
    }

    public static function fromPermissions(iterable $permissions): self
    {
        $allowMembers = [];
        $denyMembers = [];

        foreach ($permissions as $complexPermission) {
            $permissionKey = getPermissionKey($complexPermission);
            $permissionType = getPermissionType($complexPermission);

            if (!$permissionKey || !$permissionType) {
                continue;
            }

            if ($permissionType === PermissionTypeEnum::DENY) {
                $denyMembers[$permissionKey] = true;
                continue;
            }

            foreach (PermissionTypeEnum::cases() as $candidateType) {
                if ($candidateType === PermissionTypeEnum::DENY) {
                    continue;
                }

                if (PermissionTypeEnum::hasPermission($permissionType, $candidateType)) {
                    $allowMembers[self::allowMember($permissionKey, $candidateType)] = true;
                }
            }
        }

        return new self(array_keys($allowMembers), array_keys($denyMembers));
    }

    public function allows(string $permissionKey, PermissionTypeEnum $type): bool
    {
        return !$this->denies($permissionKey) && $this->hasAllowed($permissionKey, $type);
    }

    public function hasAllowed(string $permissionKey, PermissionTypeEnum $type): bool
    {
        return isset($this->allowMembers[self::allowMember($permissionKey, $type)]);
    }

    public function denies(string $permissionKey): bool
    {
        return isset($this->denyMembers[$permissionKey]);
    }

    public function allowMembers(): array
    {
        return array_keys($this->allowMembers);
    }

    public function denyMembers(): array
    {
        return array_keys($this->denyMembers);
    }

    public static function allowMember(string $permissionKey, PermissionTypeEnum $type): string
    {
        return $permissionKey . '|' . $type->value;
    }

    private function normalizeMembers(array $members): array
    {
        $normalized = [];

        foreach ($members as $member) {
            if ($member === null || $member === '') {
                continue;
            }

            $normalized[(string) $member] = true;
        }

        return $normalized;
    }
}
