<?php

namespace Kompo\Auth\Files;

class AudioPreview extends AbstractPreview
{

	public function render()
	{
		return _Audio(fileRoute($this->model->fileType, $this->model->id));
	}
}