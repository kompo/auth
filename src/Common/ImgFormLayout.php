<?php

namespace Kompo\Auth\Common;

use Kompo\Form;

abstract class ImgFormLayout extends Form
{
    public $containerClass = 'container-fluid';

    protected $imgUrl = 'images/left-column-image.png';

	public function render()
	{
		return _Columns(
            _Div(
                _Img($this->imgUrl)->class('h-screen w-full')->bgCover()->style('margin: 0 -15px'),
            )->class('hidden md:block')->col('col-md-7'),
            _Rows(
                _Div(
                    $this->rightColumnBody(),
                )->class('h-screen overflow-auto p-6'),
            )->col('col-12 col-md-5'),
		);
	}
}
