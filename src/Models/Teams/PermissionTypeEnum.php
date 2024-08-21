<?php

namespace Kompo\Auth\Models\Teams;

enum PermissionTypeEnum: int
{
    use \Kompo\Auth\Models\Traits\EnumKompo;
    
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
            self::READ => 'bg-green-500',
            self::WRITE => 'bg-blue-500',
            self::ALL => 'bg-yellow-500',
            self::DENY => 'bg-red-500',
        };
    }

    public static function values()
    {
        return collect(self::cases())->pluck('value');
    }

    public static function colors()
    {
        return collect(self::cases())->map(fn($case) => $case->color());
    }

    public static function hasPermission(self $given, self $expected)
    {
        return ($given->value & $expected->value) == $expected->value;
    }
}