<?php

namespace Kompo\Auth\Models\Phone;

use Condoedge\Utils\Models\Model;

class Phone extends Model
{
    use \Kompo\Auth\Models\Teams\BelongsToTeamTrait;

    public const TYPE_PH_WORK = 1;
    public const TYPE_PH_CELLULAR = 2;
    public const TYPE_PH_HOME = 3;
    public const TYPE_PH_OTHER = 4;
    public const TYPE_PH_FAX = 5;
    
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

    /* SCOPES */
    public function scopeMatchNumber($query, $phoneNumber)
    {
        $query->where('number_ph', $phoneNumber);
    }

    /* CALCULATED FIELDS */
    public function getFullLabelWithExtension()
    {
        return $this->getPhoneNumber() . ($this->extension_ph ? (' - ext:' . $this->extension_ph) : '');
    }

    public function getPhoneNumber()
    {
        return $this->number_ph;
    }

    public function isSameNumber($number)
    {
        return $this->getPhoneNumber() == $number; //TODO change after phone sanitizing
    }

    /* ACTIONS */
    public function setPhonable($model)
    {
        $this->phonable_type = $model->getRelationType();
        $this->phonable_id = $model->id;
    }

    public function setPhoneNumber($number)
    {
        //TODO sanitize phone number
        $this->number_ph = $number;
    }

    /* ELEMENTS */
}
