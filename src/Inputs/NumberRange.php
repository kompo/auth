<?php

namespace Kompo\Auth\Inputs;

use Kompo\Rows;

class NumberRange extends Rows
{
    static $availableMethods = ['selfGet', 'selfPost', 'refresh', 'withAllFormValues', 'rIcon', 'inputClass'];

    protected function setElementsFromArguments($args)
    {
        $this->elements = [
            _InputNumber()->placeholder('filter-min')->class('!mb-0'),
            _InputNumber()->placeholder('filter-max')->class('!mb-0'),
        ];
    }

    public function initialize($label)
    {
        parent::initialize($label);

        $this->class('flex flex-row gap-4 justify-between');
    }

    public function name($value)
    {
        foreach ($this->elements as $key => $element) {
            $this->elements[$key] = $element->name($value . "[$key]");
        }

        return $this;
    }

    public function value($value)
    {
        foreach ($this->elements as $key => $element) {
            $this->elements[$key] = $element->value($value[$key] ?? null);
        }

        return $this;
    }

    public function __call($methodName, $parameters)
    {
        if (in_array($methodName, self::$availableMethods)) {
            return $this->callFunctionOnElements($methodName, $parameters);
        }

        return parent::__call($methodName, $parameters);
    }

    public function onChange($function)
    {
        $this->callFunctionOnElements('onChange', [$function]);

        return $this;
    }

    public function callFunctionOnElements($functionName, $parameters = [])
    {
        foreach ($this->elements as $key => $element) {
            $this->elements[$key] = $element->$functionName(...$parameters);
        }

        return $this;
    }
}