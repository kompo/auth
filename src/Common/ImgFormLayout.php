<?php

namespace Kompo\Auth\Common;

use Kompo\Form;

abstract class ImgFormLayout extends Form
{
    public $containerClass = '';

    protected $imgUrl = 'images/left-column-image.png';

    protected $rightColumnBodyWrapperClass = 'justify-around md:justify-center';

	public function render()
	{
        $this->class(config('kompo-auth.img_form_layout_default_class'));

		return _Columns(
            _Div(
                _Img($this->imgUrl)->class('h-screen w-full')->bgCover(),
            )->class('relative hidden md:block')->col('col-md-7'),
            _Rows(
                _Rows(
                    $this->rightColumnBody(),
                )->class('h-screen overflow-auto p-6 md:p-8 w-full')->class($this->rightColumnBodyWrapperClass)->style('max-width:500px'),
            )->class('items-center')
            ->col('col-12 col-md-5 bg-level1'),
		)->class('no-gutters');
	}
}
