<?php

namespace Kompo\Auth\Files;

use Illuminate\Database\Eloquent\Relations\Relation;
use Kompo\Form;

class ImagePreview extends Form
{
	public $model;

	public function created()
	{
		$model = Relation::morphMap()[request('type')];

    	$this->model($model::findOrFail(request('id')));
	}

	public function render()
	{
		return _Img($this->model->name)->src(fileRoute($this->model->fileType, $this->model->id))->class('max-h-screen');
	}
}