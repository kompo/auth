<?php

namespace Kompo\Auth\Elements;

use Illuminate\Support\Collection;
use Kompo\Elements\Field;

/**
 * A field cycling through N states, each rendered with the class given at the same index in `colors`.
 */
class MultiStateCheckbox extends Field
{
    public $vueComponent = 'MultiStateCheckbox';

    protected function initialize($label)
    {
        parent::initialize($label);

        $this->noInputWrapper();
    }

    public function values($values)
    {
        return $this->config(['values' => $this->normalize($values)]);
    }

    public function colors($colors)
    {
        return $this->config(['colors' => $this->normalize($colors)]);
    }

    public function mode($mode)
    {
        return $this->config(['mode' => $mode]);
    }

    public function readonly(bool $readonly = true)
    {
        return $this->config(['readonly' => $readonly]);
    }

    protected function normalize($input): array
    {
        $arr = $input instanceof Collection ? $input->all() : (array) $input;
        return array_values($arr);
    }
}
