<?php

if (!function_exists('_ImgModel')) {
	function _ImgModel($model, $column)
	{
		$image = $model->{$column};

		if (!$image) {
			return _Img();
		}

		return _Img($image['path']);
	}
}

if (!function_exists('_ImgCover')) {
	function _ImgCover($label = '')
	{
		return _Img($label)->bgCover();
	}
}

if (!function_exists('_ImgPill')) {
	function _ImgPill($label = '', $class = null)
	{
		return _ImgCover($label)->class($class ?: 'w-12 h-12')->class('rounded-full');
	}
}
