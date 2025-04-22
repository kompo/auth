<?php

namespace Kompo\Auth\Models\Teams;

enum TeamRoleStatusEnum: int {
    use \Condoedge\Utils\Models\Traits\EnumKompo;

    case IN_PROGRESS = 1;
    case FINISHED = 2;
    case SUSPENDED = 3;

    public function label()
    {
        return match ($this) {
            self::IN_PROGRESS => __('permissions-in-progress'),
            self::FINISHED => __('permissions-finished'),
            self::SUSPENDED => __('permissions-suspended'),
        };
    }

    public function color()
    {
        return match ($this) {
            self::IN_PROGRESS => 'bg-greenlight text-greendark',
            self::FINISHED => 'bg-dangerlight text-dangerdark',
            self::SUSPENDED => 'bg-dangerlight text-dangerdark',
        };
    }

    public function canBeFinished()
    {
        return match ($this) {
            self::IN_PROGRESS => true,
            default => false,
        };
    }

    public static function getFromTeamRole($teamRole)
    {
        if ($teamRole->suspended_at) {
            return self::SUSPENDED;
        }
        
        if ($teamRole->terminated_at) {
            return self::FINISHED;
        }

        return self::IN_PROGRESS;
    }
}