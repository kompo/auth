<?php

namespace Kompo\Auth\Models\Phone;

use Kompo\Auth\Models\Model;

class Phone extends Model
{
    use \Kompo\Auth\Models\Teams\BelongsToTeamTrait;

    public const TYPE_PH_WORK = 1;
    public const TYPE_PH_CELLULAR = 2;
    public const TYPE_PH_HOME = 3;
    public const TYPE_PH_OTHER = 4;
    
    public function save(array $options = [])
    {
        $this->setTeamId();

        parent::save($options);
    }

    /* ENUMS */
    public static function getTypePhLabels()
    {
        return [
            Phone::TYPE_PH_WORK => __('Work'),
            Phone::TYPE_PH_CELLULAR => __('Cellular'),
            Phone::TYPE_PH_HOME => __('Home'),
            Phone::TYPE_PH_OTHER => __('Other'),
        ];
    }

    /* RELATIONS */

    /* ATTRIBUTES */
    public function getTypePhLabelAttribute()
    {
        return Phone::getTypePhLabels()[$this->type_ph] ?? '';
    }

    /* CALCULATED FIELDS */


    /* SCOPES */

    /* ACTIONS */

    /* ELEMENTS */
}
