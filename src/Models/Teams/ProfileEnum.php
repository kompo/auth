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
            self::GENERAL => __('profile-general'),
            self::ACCOUNTING => __('profile-accounting'),
            self::CHILD => __('profile-child'),
        };
    }
}