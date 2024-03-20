<?php

namespace Kompo\Auth\Models\Email;

use Kompo\Auth\Models\Model;

class Email extends Model
{
    use \Kompo\Auth\Models\Teams\BelongsToTeamTrait;

    public const TYPE_EM_PERSONAL = 1;
    public const TYPE_EM_WORK = 2;

    public function save(array $options = [])
    {
        $this->setTeamId();

        parent::save($options);
    }

    /* ENUMS */
    public static function getTypeEmLabels()
    {
        return [
            Email::TYPE_EM_PERSONAL => __('email-personal'),
            Email::TYPE_EM_WORK => __('email-work'),
        ];
    }

    /* RELATIONSHIPS */
    public function emailable()
    {
        return $this->morphTo();
    }

    /* ATTRIBUTES */
    public function getTypeEmLabelAttribute()
    {
        return Email::getTypeEmLabels()[$this->type_em];
    }

    /* CALCULATED FIELDS */
}
