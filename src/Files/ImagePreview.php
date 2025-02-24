<?php

namespace Kompo\Auth\Files;

class ImagePreview extends AbstractPreview
{
	public function render()
	{
		return _Img($this->model->name)->src(fileRoute($this->model->fileType, $this->model->id))->class('max-h-screen');
	}
}