<?php

namespace Kompo\Auth\Models\Teams;

enum TeamRoleStatusEnum: int {
    use \Kompo\Auth\Models\Traits\EnumKompo;

    case IN_PROGRESS = 1;
    case FINISHED = 2;
    case SUSPENDED = 3;

    public function label()
    {
        return match ($this) {
            self::IN_PROGRESS => __('translate.in-progress'),
            self::FINISHED => __('translate.finished'),
            self::SUSPENDED => __('translate.suspended'),
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
        if ($teamRole->deleted_at) {
            return self::FINISHED;
        }

        return self::IN_PROGRESS;
    }
}