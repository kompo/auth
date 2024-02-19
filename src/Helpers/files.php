<?php 

if (!function_exists('appendBeforeExtension')) {
	function appendBeforeExtension($path, $appendText)
	{
	    return substr($path, 0, strrpos($path, '.')).$appendText.'.'.substr($path, strrpos($path, '.') + 1);
	}
}