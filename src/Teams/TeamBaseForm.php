<?php

namespace Kompo\Auth\Teams;

use Kompo\Form;

abstract class TeamBaseForm extends Form
{
    protected $_Title = 'Override this in child class';
    protected $_Description = 'Override this in child class';

    public function created()
    {
        $this->model(currentTeam());
    }

    abstract protected function body();

    public function render()
    {
        return _CardSettings($this->_Title, $this->_Description, $this->body());
    }
}