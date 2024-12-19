<?php

namespace Kompo\Auth\Models\LabelCasts;

class EnumLabelCast extends AbstractLabelCast
{
    public function convert($value, $column)
    {
        if (!$value) return null;
        
        return method_exists($value, 'label') ? $value->label() : $value->name;
    }
}