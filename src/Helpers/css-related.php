<?php 

use \Kompo\Elements\Element;

/* GENERAL CARD SETTINGS */
Element::macro('scrollY', fn($height = '300px') => $this->class('overflow-y-auto mini-scroll')->style('min-height:'.$height));

\Kompo\Elements\Element::macro('miniTitle', function(){
    return $this->class('text-sm text-level3 uppercase leading-widest font-bold');
});

function _MiniTitle($label)
{
    return _Html($label)->miniTitle();
}