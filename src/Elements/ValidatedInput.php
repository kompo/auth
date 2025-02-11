<?php

namespace Kompo\Auth\Elements;

use Kompo\Input;

class ValidatedInput extends Input
{
    public $vueComponent = 'ValidatedInput';

    public function initialize($label)
    {
        parent::initialize($label);

        $this->invalidClass('!border !border-red-600');
    }

    public function invalidClass($class) 
    {
        return $this->config(['invalidClass' => $class]);
    }

    public function formatModels($format) 
    {
        return $this->config(['formatModels' => $format]);
    }

    /**
     * We allow to write just if the user follows the next format. Use validate method if
     * you want to let the user write whatever he wants an show a validation error
     */
    public function allow($format): mixed
    {
        return $this->config(['allowFormat' => $format]);
    }

    /**
     * If the user doesn't follow the next format we'll show an error, just for front
     * @param mixed $value
     */
    public function validate($format) 
    {
        return $this->config(['validateFormat' => $format]);
    }
}