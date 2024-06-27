<?php

namespace Kompo\Auth\Models\Monitoring;

enum NotificationTypeEnum: int
{
    use \Kompo\Auth\Models\Traits\EnumKompo;

    case GENERIC = 1;

    public function label()
    {
        return match ($this) {
            self::GENERIC => 'Generic',
        };
    }

    public function getContent($about)
    {
        return match ($this) {
            self::GENERIC => null,
        };
    }
}