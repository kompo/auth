<?php

namespace Kompo\Auth\Files;

use Kompo\Auth\Common\Form;

class AbstractPreview extends Form
{
    public $model;

    public function created()
    {
        $model = Relation::morphMap()[request('type')];

    	$this->model($model::findOrFail(request('id')));
    }
}