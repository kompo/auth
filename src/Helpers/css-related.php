<?php 

use \Kompo\Elements\Element;

/* GENERAL SHORTCUTS */
Element::macro('mb4', fn() => $this->class('mb-4'));
Element::macro('mb2', fn() => $this->class('mb-2'));
Element::macro('p4', fn() => $this->class('p-4'));

/* GENERAL CARD SETTINGS */
Element::macro('scrollY', fn($height = '300px') => $this->class('overflow-y-auto mini-scroll')->style('min-height:'.$height));

\Kompo\Elements\Element::macro('miniTitle', function(){
    return $this->class('text-sm text-level3 uppercase leading-widest font-bold');
});

if (!function_exists('_MiniTitle')) {
    function _MiniTitle($label)
    {
        return _Html($label)->miniTitle();
    }
}

if (!function_exists('_PageTitle')) {
    function _PageTitle($label)
    {
        return _H1($label)->class('text-2xl sm:text-3xl font-bold');
    }
}

function _ChatCount($count)
{
    return _Html($count)
        ->icon(
            _Sax('message',20)->class('text-xl')
        )
        ->class('flex items-center');
}

function _DiffDate($date)
{
    if(!$date)
        return;

    return _Html($date->diffForHumans())->class('text-xs text-gray-600 whitespace-nowrap');
}

