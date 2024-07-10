<?php

namespace Kompo\Auth\Files;

use Illuminate\Database\Eloquent\Relations\Relation;
use Kompo\Form;

class AudioPreview extends Form
{
	public $model;

	public function created()
	{
		$model = Relation::morphMap()[request('type')];

    	$this->model($model::findOrFail(request('id')));
	}

	public function render()
	{
		return _Audio(fileRoute($this->model->fileType, $this->model->id));
	}
}