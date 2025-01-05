<?php

namespace Kompo\Auth\Common;

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
                _Img($this->imgUrl)->class('h-screen w-full')->disk('public')->bgCover(),
            )->class('relative hidden md:block')->col('col-md-7'),
            _Rows(
                _LocaleSwitcher()->class('absolute top-0 right-0'),
                _Rows(
                    $this->rightColumnBody(),
                )->class('h-screen overflow-auto p-6 md:p-8 w-full')->class($this->rightColumnBodyWrapperClass)->style('max-width:500px'),
            )->class('items-center')
            ->col('col-12 col-md-5 bg-greenmain'),
		)->class('no-gutters');
	}
}
