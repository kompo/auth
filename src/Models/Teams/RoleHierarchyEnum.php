<?php

namespace Kompo\Auth\Models\Teams;

enum RoleHierarchyEnum: string
{
    use \Kompo\Auth\Models\Traits\EnumKompo;

    case DIRECT = 'B';
    case DIRECT_AND_BELOW = 'A';
    case DIRECT_AND_NEIGHBOURS = 'C';
    case DIRECT_AND_BELOW_AND_NEIGHBOURS = 'E';
    case DISABLED_BELOW = 'D';

    public function label()
    {
        return match ($this) 
        {
            static::DIRECT => 'translate.role-direct',
            static::DIRECT_AND_BELOW => 'translate.role-direct-and-below',
            static::DIRECT_AND_NEIGHBOURS => 'translate.role-direct-and-neighbours',
            static::DIRECT_AND_BELOW_AND_NEIGHBOURS => 'translate.role-direct-and-below-and-neighbours',
            static::DISABLED_BELOW => 'Disabled for this team and below',
        };
    }

    public function accessGrant()
    {
        return match ($this) 
        {
            static::DIRECT => true,
            static::DIRECT_AND_BELOW => true,
            static::DIRECT_AND_NEIGHBOURS => true,
            static::DIRECT_AND_BELOW_AND_NEIGHBOURS => true,
            static::DISABLED_BELOW => false,
        };
    }

    public function accessGrantBelow()
    {
        return match ($this) 
        {
            static::DIRECT => false,
            static::DIRECT_AND_BELOW => true,
            static::DIRECT_AND_NEIGHBOURS => false,
            static::DIRECT_AND_BELOW_AND_NEIGHBOURS => true,
            static::DISABLED_BELOW => false,
        };
    }

    public function accessGrantNeighbours()
    {
        return match ($this) 
        {
            static::DIRECT => false,
            static::DIRECT_AND_BELOW => false,
            static::DIRECT_AND_NEIGHBOURS => true,
            static::DIRECT_AND_BELOW_AND_NEIGHBOURS => true,
            static::DISABLED_BELOW => false,
        };
    }

    public static function getFinal(array $hierarchies)
    {
        $hasDirectAndBelow = in_array(static::DIRECT_AND_BELOW, $hierarchies);
        $hasDirectAndNeighbours = in_array(static::DIRECT_AND_NEIGHBOURS, $hierarchies);
        $hasComplete = $hasDirectAndBelow && $hasDirectAndNeighbours;

        if ($hasComplete) return static::DIRECT_AND_BELOW_AND_NEIGHBOURS;
 
        if ($hasDirectAndBelow) return static::DIRECT_AND_BELOW;

        if ($hasDirectAndNeighbours) return static::DIRECT_AND_NEIGHBOURS;

        return in_array(static::DIRECT, $hierarchies) ? static::DIRECT : null;
    }
}