<?php 

namespace Kompo\Auth\Models\Traits;

trait EnumKompo
{
    public static function optionsWithLabels()
    {
        return collect(self::cases())->mapWithKeys(fn($enum) => [
            $enum->value => $enum->label(),
        ]);
    }
}