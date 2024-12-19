<?php

namespace Kompo\Auth\Models\LabelCasts;

class RelationshipLabelCast extends AbstractLabelCast
{
    public function convert($value, $column)
    {
        if (!$value) return null;

        if (!count($this->options)) return $value;

        $class = $this->options['class'] ?? null;
        $column = $this->options['column'] ?? null;
        $method = $this->options['method'] ?? null;

        if (!$class || (!$column && !$method)) return $value;

        if ($method) {
            return $class::find($value)->$method();
        }

        return $class::find($value)->getAttribute($column);
    }
}