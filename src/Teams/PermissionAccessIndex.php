<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Facades\Schema;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

final class PermissionAccessIndex
{
    private array $allowMembers;
    private array $denyMembers;

    /** @var bool|null cached result of `supported_types` column existence */
    private static ?bool $supportedTypesColumnAvailable = null;

    public function __construct(array $allowMembers = [], array $denyMembers = [])
    {
        $this->allowMembers = $this->normalizeMembers($allowMembers);
        $this->denyMembers = $this->normalizeMembers($denyMembers);
    }

    public static function fromPermissions(iterable $permissions): self
    {
        // Materialize once so we can collect distinct keys for a single bulk
        // lookup of `supported_types` before the expansion loop.
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

    /**
     * @param  list<array{0: string, 1: PermissionTypeEnum}>  $rows
     * @return array<string, int>  permission_key => supported_types bitmask
     */
    private static function loadSupportedTypesMap(array $rows): array
    {
        if (!self::supportedTypesColumnAvailable()) {
            return [];
        }

        $keys = [];
        foreach ($rows as [$key, $_]) {
            $keys[$key] = true;
        }

        try {
            return Permission::whereIn('permission_key', array_keys($keys))
                ->pluck('supported_types', 'permission_key')
                ->map(fn($v) => (int) $v)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
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
