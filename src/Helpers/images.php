<?php 

function _ImgModel($model, $column)
{
	$image = $model->{$column};

	if (!$image) {
		return _Img();
	}

	return _Img($image['path']);
}