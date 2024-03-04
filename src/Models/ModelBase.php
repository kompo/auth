<?php

namespace Kompo\Auth\Models;

use Illuminate\Database\Eloquent\Model as LaravelModel;

class ModelBase extends LaravelModel
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
    use \Kompo\Auth\Models\Traits\HasRelationType;

    public const DISPLAY_ATTRIBUTE = null; //OVERRIDE IN CLASS

    /* CALCULATED FIELDS */
    public static function getNameDisplayKey()
    {
        return static::DISPLAY_ATTRIBUTE ?: static::SEARCHABLE_NAME_ATTRIBUTE;    
    }
}
