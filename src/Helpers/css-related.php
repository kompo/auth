<?php 

use \Kompo\Elements\Element;

/* GENERAL CARD SETTINGS */
Element::macro('scrollY', fn($height = '300px') => $this->class('overflow-y-auto mini-scroll')->style('min-height:'.$height));