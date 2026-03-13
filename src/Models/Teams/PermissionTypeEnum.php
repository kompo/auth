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
}