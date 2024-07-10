<?php

namespace Kompo\Auth\Files;

use Illuminate\Database\Eloquent\Relations\Relation;
use Kompo\Form;

class PdfPreview extends Form
{
	public $model;

	public function created()
	{
		$model = Relation::morphMap()[request('type')];

    	$this->model($model::findOrFail(request('id')));
	}

	public function render()
	{
		return _Html('<embed src="'.fileRoute($this->model->fileType, $this->model->id).'" frameborder="0" width="100%" height="100%">')
			->style('height:95vh; width: 95vw');
	}
}
