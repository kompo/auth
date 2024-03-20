<?php

use \Kompo\Elements\Element;

/* BASE BUTTONS */
Element::macro('bigButton', fn() => $this->class('px-6 py-3 text-lg'));

if (!function_exists('_ButtonBig')) {
	function _ButtonBig($label = '')
	{
		return _Button($label)->bigButton();
	}
}


/* CRUD ICONS */
Element::macro('iconCreate', fn() => $this->icon(_Sax('add',22)));
Element::macro('iconUpdate', fn() => $this->icon(_Sax('edit', 22)));
Element::macro('iconDelete', fn() => $this->icon(_Sax('trash',22)));

if (!function_exists('_Create')) {
	function _Create($label = '')
	{
		return _Button($label)->iconCreate();
	}
}

if (!function_exists('_CreateLink')) {
	function _CreateLink($label = '')
	{
		return _Link($label)->iconCreate();
	}
}

if (!function_exists('_Update')) {
	function _Update($label = '')
	{
		return _Link($label)->iconUpdate();
	}
}

if (!function_exists('_Delete')) {
	function _Delete($model, $label = '')
	{
		return _DeleteLink($label)->byKey($model)->iconDelete();
	}
}

/* CRUD CLASSES */
Element::macro('classDelete', fn() => $this->class('text-gray-600 flex text-right justify-end'));

/* IN CARDS */
if (!function_exists('_CreateCard')) {
	function _CreateCard($label = '')
	{
		return _Link($label)->iconCreate()->class('mb-2');
	}
}

/* IN TABS */
if (!function_exists('_TabLink')) {
	function _TabLink($label, $active)
	{
		return _Link($label)
			->class('px-2 sm:px-4 py-1 rounded-none')
			->class($active ? 'border-b-2 border-info font-medium text-level3' : '');
	}
}

/* MISC */
if (!function_exists('_BackLink')) {
	function _BackLink($label = '')
	{
		return _Link($label)->icon(_Sax('arrow-left-1'))->class('text-gray-400');
	}
}

if (!function_exists('_OutlinedLink')) {
	function _OutlinedLink($label = '')
	{
		return _Link($label)->button()->outlined();
	}
}

/* LAYOUTS */
if (!function_exists('_TwoColumnsButtons')) {
	function _TwoColumnsButtons()
	{
		return _Columns(
			...func_get_args()
		);
	}
}