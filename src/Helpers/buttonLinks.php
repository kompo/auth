<?php

use \Kompo\Elements\Element;

/* BASE BUTTONS AND LINKS */
Element::macro('button2', fn() => $this->class('!bg-warning !text-greendark'));
Element::macro('buttonBig', fn() => $this->class('!px-6 !py-3 !text-lg'));

if (!function_exists('_ButtonBig')) {
	function _ButtonBig($label = '')
	{
		return _Button($label)->buttonBig();
	}
}

if (!function_exists('_ButtonOutlined')) {
	function _ButtonOutlined($label = '')
	{
		return _Button($label)->outlined();
	}
}

if (!function_exists('_Button2')) {
	function _Button2($label = '')
	{
		return _Button($label)->button2();
	}
}

if (!function_exists('_Button2Outlined')) {
	function _Button2Outlined($label = '')
	{
		return _Button($label)->outlined()->class('text-warning border-warning');
	}
}

if (!function_exists('_LinkButton')) {
	function _LinkButton($label = '')
	{
		return _Link($label)->button();
	}
}

if (!function_exists('_LinkOutlined')) {
	function _LinkOutlined($label = '')
	{
		return _Link($label)->button()->outlined();
	}
}

if (!function_exists('_Link2')) {
	function _Link2($label = '')
	{
		return _Link($label)->class('!text-warning');
	}
}

if (!function_exists('_Link2Button')) {
	function _Link2Button($label = '')
	{
		return _Link($label)->button()->button2();
	}
}

if (!function_exists('_Link2Outlined')) {
	function _Link2Outlined($label = '')
	{
		return _Link($label)->button()->outlined()->class('!text-warning !border-warning');
	}
}

if (!function_exists('_SubmitButtonBig')) {
	function _SubmitButtonBig($label = '')
	{
		return _SubmitButton($label)->buttonBig();
	}
}

if (!function_exists('_SubmitButton2')) {
	function _SubmitButton2($label = '')
	{
		return _SubmitButton($label)->button2();
	}
}

if (!function_exists('_SubmitButtonBig2')) {
	function _SubmitButtonBig2($label = '')
	{
		return _SubmitButton($label)->button2()->buttonBig();
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
		return _DeleteLink($label)->byKey($model)->iconDelete()->class('opacity-40');
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

/* LAYOUTS */
if (!function_exists('_TwoColumnsButtons')) {
	function _TwoColumnsButtons($el1 = null, $el2 = null, $defaultClass = '[&>*]:mt-2 -mt-2')
	{
		return _Columns(
			$el1?->class('w-full'),
			$el2?->class('w-full'),
		)->class($defaultClass);
	}
}
