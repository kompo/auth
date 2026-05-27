<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Facades\Schema;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

final class PermissionAccessIndex
{
    private array $allowMembers;
    private array $denyMembers;

    private static ?bool $supportedTypesColumnAvailable = null;

    private static array $supportedTypesMap = [];

    public function __construct(array $allowMembers = [], array $denyMembers = [])
    {
        $this->allowMembers = $this->normalizeMembers($allowMembers);
        $this->denyMembers = $this->normalizeMembers($denyMembers);
    }

    public static function fromPermissions(iterable $permissions): self
    {
        $rows = [];
        foreach ($permissions as $complexPermission) {
            $key = getPermissionKey($complexPermission);
            $type = getPermissionType($complexPermission);
            if ($key && $type) {
                $rows[] = [$key, $type];
            }
        }

        if (empty($rows)) {
            return new self();
        }

        $supportedMap = self::loadSupportedTypesMap($rows);

        $allowMembers = [];
        $denyMembers = [];

        foreach ($rows as [$permissionKey, $permissionType]) {
            if ($permissionType === PermissionTypeEnum::DENY) {
                $denyMembers[$permissionKey] = true;
                continue;
            }

            $supportedBitmask = $supportedMap[$permissionKey] ?? PermissionTypeEnum::ALL->value;

            foreach (PermissionTypeEnum::cases() as $candidateType) {
                if ($candidateType === PermissionTypeEnum::DENY) {
                    continue;
                }
                if (!$candidateType->isSupportedBy($supportedBitmask)) {
                    continue;
                }
                if (PermissionTypeEnum::hasPermission($permissionType, $candidateType)) {
                    $allowMembers[self::allowMember($permissionKey, $candidateType)] = true;
                }
            }
        }

        return new self(array_keys($allowMembers), array_keys($denyMembers));
    }

    private static function loadSupportedTypesMap(array $rows): array
    {
        if (!self::supportedTypesColumnAvailable()) {
            return [];
        }

        $requested = [];
        foreach ($rows as [$key, $_]) {
            $requested[$key] = true;
        }

        $missing = array_diff_key($requested, self::$supportedTypesMap);

        if (!empty($missing)) {
            try {
                $fetched = Permission::whereIn('permission_key', array_keys($missing))
                    ->pluck('supported_types', 'permission_key')
                    ->map(fn($v) => (int) $v)
                    ->all();

                self::$supportedTypesMap += $fetched;
            } catch (\Throwable $e) {
                foreach ($missing as $key => $_) {
                    self::$supportedTypesMap[$key] = PermissionTypeEnum::ALL->value;
                }
            }
        }

        return array_intersect_key(self::$supportedTypesMap, $requested);
    }

    public static function flushSupportedTypesMap(): void
    {
        self::$supportedTypesMap = [];
    }

    private static function supportedTypesColumnAvailable(): bool
    {
        if (self::$supportedTypesColumnAvailable !== null) {
            return self::$supportedTypesColumnAvailable;
        }

        try {
            return self::$supportedTypesColumnAvailable =
                Schema::hasColumn('permissions', 'supported_types');
        } catch (\Throwable $e) {
            return self::$supportedTypesColumnAvailable = false;
        }
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
