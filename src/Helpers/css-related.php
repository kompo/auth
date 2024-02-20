<?php 

use \Kompo\Elements\Element;

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

