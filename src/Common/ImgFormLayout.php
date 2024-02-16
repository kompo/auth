<?php

namespace Kompo\Auth\Common;

use Kompo\Form;

abstract class ImgFormLayout extends Form
{
    public $containerClass = '';

    protected $imgUrl = 'images/left-column-image.png';

	public function render()
	{
		return _Columns(
            _Div(
                _Img($this->imgUrl)->class('h-full w-full bg-cover'),
            )->class('hidden md:block')->col('col-md-7'),
            _Rows(
                $this->rightColumnBody(),
            )->col('col-12 col-md-5'),
		);
	}
}
