<?php

namespace Kompo\Auth\Models\Teams;

enum RoleHierarchyEnum: string
{
    use \Kompo\Auth\Models\Traits\EnumKompo;

    case DIRECT = 'B';
    case DIRECT_AND_BELOW = 'A';
    case DISABLED_BELOW = 'D';

    public function label()
    {
        return match ($this) 
        {
            static::DIRECT => 'Direct team access',
            static::DIRECT_AND_BELOW => 'Direct and all teams below access',
            static::DISABLED_BELOW => 'Disabled for this team and below',
        };
    }

    public function accessGrant()
    {
        return match ($this) 
        {
            static::DIRECT => true,
            static::DIRECT_AND_BELOW => true,
            static::DISABLED_BELOW => false,
        };
    }

    public function accessGrantBelow()
    {
        return match ($this) 
        {
            static::DIRECT => false,
            static::DIRECT_AND_BELOW => true,
            static::DISABLED_BELOW => false,
        };
    }
}