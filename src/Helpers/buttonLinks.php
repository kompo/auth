<?php

use \Kompo\Elements\Element;

/* CRUD ICONS */
Element::macro('iconCreate', fn() => $this->icon(_Sax('add',22)));
Element::macro('iconUpdate', fn() => $this->icon(_Sax('edit', 22)));
Element::macro('iconDelete', fn() => $this->icon(_Sax('trash',22)));

function _Create($label = '')
{
	return _Button($label)->iconCreate();
}

function _CreateLink($label = '')
{
	return _Link($label)->iconCreate();
}

function _Update($label = '')
{
	return _Link($label)->iconUpdate();
}

function _Delete($model, $label = '')
{
	return _DeleteLink($label)->byKey($model)->iconDelete();
}

/* CRUD CLASSES */
Element::macro('classDelete', fn() => $this->class('text-gray-600 flex text-right justify-end'));

/* IN CARDS */
function _CreateCard($label = '')
{
	return _Link($label)->iconCreate()->class('mb-2');
}

/* IN TABS */
function _TabLink($label, $active)
{
    return _Link($label)
        ->class('px-2 sm:px-4 py-1 rounded-none')
        ->class($active ? 'border-b-2 border-info font-medium text-level3' : '');
}

/* MISC */
function _BackLink($label = '')
{
	return _Link($label)->icon(_Sax('arrow-left-1'))->class('text-gray-400');
}
