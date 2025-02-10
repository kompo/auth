<?php

use \Kompo\Elements\Element;

/* ALMOST CARDS */
if (!function_exists("_DashedBox")) {
	function _DashedBox($label, $varClass = 'py-32 text-2xl')
	{
		return _Html($label)
			->class('border border-dashed border-gray-300 text-center font-bold text-gray-300 rounded-2xl')
			->class($varClass);
	}
}

if (!function_exists("_CardIconStat")) {
	function _CardIconStat($icon, $label, Element $statEl)
	{
		return _Card(
			_FlexBetween(
				_Sax($icon, 48),
				_Rows(
					_TextSm($label),
					$statEl,
				)->class('text-right'),
			),
		)->class('p-4');
	}
}

if (!function_exists("_CardIconTitleDesc")) {
	function _CardIconTitleDesc($icon, $title, $desc)
	{
		return _Card(
			_Flex4(
				_Sax($icon, 48),
				_Rows(
					_Html($title),
					_TextSm($desc),
				),
			),
		)->class('p-4');
	}
}

if (!function_exists("_CardWarning")) {
	function _CardWarning()
	{
		return _Card(
			func_get_args()
		)->class('bg-danger bg-opacity-30 text-danger p-4')
		->icon(
			_Sax('info-circle')->class('text-3xl mr-4')
		)->alignCenter();
	}
}

if (!function_exists("_CardSettings")) {
	function _CardSettings($title, $description, $kompoEl)
	{
		return _Columns(
			_Rows(
				_TitleCard($title),
				_TextSmGray($description),
			)->col('col-md-4'),
			_Rows(
				_Rows(
					$kompoEl
				)->class('border-l border-level3 mb-4 p-4')
			)->col('col-md-8')
		)->class('border-b border-level3');
	}
}

/* GENERAL CARD SETTINGS */
Element::macro('kompoCard', fn() => $this->class('rounded-2xl mb-4'));

if (!function_exists("_Card")) {
	function _Card()
	{
		return _Rows(
			...func_get_args()
		)->kompoCard();
	}
}

/* COLORED CARDS */
Element::macro('cardLevel1', fn() => $this->kompoCard()->class('bg-greenmain'));
Element::macro('cardLevel2', fn() => $this->kompoCard()->class('bg-warning'));
Element::macro('cardLevel3', fn() => $this->kompoCard()->class('bg-level3'));
Element::macro('cardLevel4', fn() => $this->kompoCard()->class('bg-level4'));

if (!function_exists("_CardLevel1")) {
	function _CardLevel1()
	{
		return _Rows(
			...func_get_args()
		)->cardLevel1();
	}
}

if (!function_exists("_CardLevel2")) {
	function _CardLevel2()
	{
		return _Rows(
			...func_get_args()
		)->cardLevel2();
	}
}

if (!function_exists("_CardLevel3")) {
	function _CardLevel3()
	{
		return _Rows(
			...func_get_args()
		)->cardLevel3();
	}
}

if (!function_exists("_CardLevel4")) {
	function _CardLevel4()
	{
		return _Rows(
			...func_get_args()
		)->cardLevel4();
	}
}

/* WHITE CARDS */
Element::macro('cardWhite', fn() => $this->kompoCard()->class('bg-white'));

if (!function_exists("_CardWhite")) {
	function _CardWhite()
	{
		return _Rows(
			...func_get_args()
		)->cardWhite();
	}
}

if (!function_exists("_CardWhiteP4")) {
	function _CardWhiteP4()
	{
		return _CardWhite(
			...func_get_args()
		)->class('p-4');
	}
}

if (!function_exists("_CardLevel5")) {
	function _CardLevel5()
	{
		return _Card(
			...func_get_args()
		)->class('!bg-level5 p-4');
	}
}

/* GRAY CARDS */
Element::macro('cardGray50', fn() => $this->kompoCard()->class('bg-gray-50 whiteField'));
Element::macro('cardGray100', fn() => $this->kompoCard()->class('bg-gray-100 whiteField'));
Element::macro('cardGray200', fn() => $this->kompoCard()->class('bg-gray-200 whiteField'));
Element::macro('cardGray300', fn() => $this->kompoCard()->class('bg-level5 whiteField'));

if (!function_exists("_CardGray50")) {
	function _CardGray50()
	{
		return _Rows(
			...func_get_args()
		)->cardGray50();
	}
}

if (!function_exists("_CardGray100")) {
	function _CardGray100()
	{
		return _Rows(...func_get_args())->cardGray100();
	}
}

if (!function_exists("_CardGray100P4")) {
	function _CardGray100P4()
	{
		return _CardGray100(...func_get_args())->class('p-4');
	}
}

if (!function_exists("_CardGray200")) {
	function _CardGray200()
	{
		return _Rows(
			...func_get_args()
		)->cardGray200();
	}
}

if (!function_exists("_CardGray300")) {
	function _CardGray300()
	{
		return _Rows(
			...func_get_args()
		)->cardGray300();
	}
}
