<?php 

function _Sax($path, $dimension = 24)
{
	return _Html(_SaxSvg($path, $dimension));
}

function _SaxSvg($path, $dimension = 24)
{
	$svgHtml = file_get_contents(public_path('icons/'.$path.'.svg'));

	$svgHtml = str_replace('"#292D32"', '"currentColor"', $svgHtml);
	$svgHtml = str_replace('height="24"', 'height="'.$dimension.'"', $svgHtml);
	$svgHtml = str_replace('width="24"', 'width="'.$dimension.'"', $svgHtml);

	return $svgHtml;
}

function _HtmlSax($label = '')
{
	return _Html($label)->class('inline-flex items-center');
}