<?php

namespace Kompo\Auth\Models\LabelCasts;

use Kompo\Auth\Models\Model;

abstract class AbstractLabelCast
{
    protected Model $model;
    protected $options;

    public function __construct(Model $model, array $options = [])
    {
        $this->model = $model;
        $this->options = $options;
    }
    
    abstract public function convert($value, $column);

    public function getLabel($value, $column)
    {
        return $this->convert($value, $column);
    }
}