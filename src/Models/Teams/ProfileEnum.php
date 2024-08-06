<?php

namespace Kompo\Auth\Models\Teams;

enum ProfileEnum: int
{
    use \Kompo\Auth\Models\Traits\EnumKompo;
    CASE GENERAL = 1;
    case ACCOUNTING = 2;
    case CHILD = 3; 

    public function label()
    {
        return match($this) {
            self::GENERAL => 'translate.profile-general',
            self::ACCOUNTING => 'translate.profile-accounting',
            self::CHILD => 'translate.profile-child',
        };
    }
}