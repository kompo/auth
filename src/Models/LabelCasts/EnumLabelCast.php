<?php

namespace Kompo\Auth\Models\LabelCasts;

use BackedEnum;

class EnumLabelCast extends AbstractLabelCast
{
    public function convert($value, $column)
    {
        if (!$value) return null;

        if (!($value instanceof BackedEnum)) {
            $enum = $this->options['enum'] ?? $this->model->getCasts()[$column] ?? null;

            if (is_null($enum)) return null;

            $value = $enum::from($value);
        }

        return method_exists($value, 'label') ? $value->label() : $value->name;
    }
}