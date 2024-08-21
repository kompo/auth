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
            static::DIRECT => 'permissions-roll-direct',
            static::DIRECT_AND_BELOW => 'permissions-roll-direct-and-down',
            static::DIRECT_AND_NEIGHBOURS => 'permissions-roll-direct-and-neighbours',
            static::DIRECT_AND_BELOW_AND_NEIGHBOURS => 'permissions-roll-direct-and-down-and-neighbours',
            static::DISABLED_BELOW => 'permissions-disabled-for-this-team-and-down',
            default => 'translate.unknown-hierarchy',
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
            default => false,
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
            default => false,
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
            default => false,
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