<?php 

/* FILE UTILITIES METHODS */
if (!function_exists('appendBeforeExtension')) {
	function appendBeforeExtension($path, $appendText)
	{
	    return substr($path, 0, strrpos($path, '.')).$appendText.'.'.substr($path, strrpos($path, '.') + 1);
	}
}

/* STORAGE METHODS */
if (!function_exists('publicUrlFromPath')) {
	function publicUrlFromPath($path, $defaultUrl = null)
	{
		if (\Storage::disk('public')->exists($path)) {
			return \Storage::disk('public')->url($path);
		}

		return $defaultUrl;
	}
}

if (!function_exists('publicUrlFromFileModel')) {
	function publicUrlFromFileModel($file, $defaultUrl = null)
	{
		if ($file->path ?? false) {
			return publicUrlFromPath($file->path, $defaultUrl);
		}

		return $defaultUrl;
	}
}