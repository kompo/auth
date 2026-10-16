<?php

namespace Kompo\Auth\Models\Teams;

/**
 * STRUCTURAL: declared in code, always applied at the child's level.
 * RECOMMENDED: editable by admins, applied as READ only when the matrix toggle is on.
 */
enum PermissionDependencyTypeEnum: int
{
    use \Condoedge\Utils\Models\Traits\EnumKompo;

    case STRUCTURAL = 1;
    case RECOMMENDED = 2;

    public function label()
    {
        return match ($this) {
            self::STRUCTURAL => 'auth-permission-dependency-structural',
            self::RECOMMENDED => 'auth-permission-dependency-recommended',
        };
    }
}
