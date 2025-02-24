<?php

namespace Kompo\Auth\Files;

class PdfPreview extends AbstractPreview
{
	public function render()
	{
		return _Html('<embed src="'.fileRoute($this->model->fileType, $this->model->id).'" frameborder="0" width="100%" height="100%">')
			->style('height:95vh; width: 95vw');
	}
}
