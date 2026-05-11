<?php

namespace Kompo\Auth\Models\Teams;

enum PermissionTypeEnum: int
{
    use \Condoedge\Utils\Models\Traits\EnumKompo;
    
    case READ = 1;
    case WRITE = 3;
    case ALL = 7;

    case DENY = 100;

    public function label()
    {
        return match($this) {
            self::READ => 'permissions-permission-read',
            self::WRITE => 'permissions-permission-write',
            self::ALL => 'permissions-permission-all',
            self::DENY => 'permissions-permission-deny',
        };
    }

    public function explanation()
    {
        return match($this) {
            self::READ => 'permissions-permission-read-explanation',
            self::WRITE => 'permissions-permission-write-explanation',
            self::ALL => 'permissions-permission-all-explanation',
            self::DENY => 'permissions-permission-deny-explanation',
        };
    }

    public function visibleInSelects()
    {
        return match ($this) {
            self::WRITE => false, //We don't want to show the WRITE permission in the select. We already show the ALL permission.
            default => true,
        };
    }

    public function code()
    {
        return match($this) {
            self::READ => 'read',
            self::WRITE => 'write',
            self::ALL => 'all',
            self::DENY => 'deny',
        };
    }

    public function color()
    {
        return match($this) {
            self::READ => 'bg-blue-500',
            self::WRITE => 'bg-yellow-500',
            self::ALL => 'bg-green-500',
            self::DENY => 'bg-red-500',
        };
    }

    public static function values()
    {
        static $cached = null;
        return $cached ??= collect(self::cases())->filter(fn($case) => $case->visibleInSelects())->pluck('value')->all();
    }

    public static function colors()
    {
        static $cached = null;
        return $cached ??= collect(self::cases())->filter(fn($case) => $case->visibleInSelects())->map(fn($case) => $case->color())->values()->all();
    }

    public static function hasPermission(self $given, self $expected)
    {
        return ($given->value & $expected->value) == $expected->value;
    }

    /**
     * Visible cases narrowed to those the given permission supports.
     * DENY is always included — it's a separate axis from CRUD.
     *
     * @return list<int> raw enum values (matches `values()` shape)
     */
    public static function forPermission(\Kompo\Auth\Models\Teams\Permission $permission): array
    {
        $supported = (int) ($permission->supported_types ?? self::ALL->value);

        return collect(self::cases())
            ->filter(fn($case) => $case->visibleInSelects() && $case->isSupportedBy($supported))
            ->pluck('value')
            ->values()
            ->all();
    }

    public static function colorsForPermission(\Kompo\Auth\Models\Teams\Permission $permission): array
    {
        $supported = (int) ($permission->supported_types ?? self::ALL->value);

        return collect(self::cases())
            ->filter(fn($case) => $case->visibleInSelects() && $case->isSupportedBy($supported))
            ->map(fn($case) => $case->color())
            ->values()
            ->all();
    }

    /**
     * Union of supported types across every permission in the section.
     */
    public static function forSection(\Kompo\Auth\Models\Teams\PermissionSection $section): array
    {
        $union = $section->permissions->reduce(
            fn(int $acc, $p) => $acc | (int) ($p->supported_types ?? self::ALL->value),
            0,
        );

        return collect(self::cases())
            ->filter(fn($case) => $case->visibleInSelects() && $case->isSupportedBy($union))
            ->pluck('value')
            ->values()
            ->all();
    }

    public static function colorsForSection(\Kompo\Auth\Models\Teams\PermissionSection $section): array
    {
        $union = $section->permissions->reduce(
            fn(int $acc, $p) => $acc | (int) ($p->supported_types ?? self::ALL->value),
            0,
        );

        return collect(self::cases())
            ->filter(fn($case) => $case->visibleInSelects() && $case->isSupportedBy($union))
            ->map(fn($case) => $case->color())
            ->values()
            ->all();
    }

    /**
     * Is this enum case supported by the given bitmask? DENY is always
     * supported regardless of `supported_types` (separate axis).
     */
    public function isSupportedBy(int $bitmask): bool
    {
        if ($this === self::DENY) {
            return true;
        }

        return ($bitmask & $this->value) === $this->value;
    }
}