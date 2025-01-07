<?php

/* TITLES */
\Kompo\Elements\Element::macro('titleMain', fn() => $this->class('text-2xl font-medium'));
\Kompo\Elements\Element::macro('titleCard', fn() => $this->class('text-base font-semibold mb-2'));
\Kompo\Elements\Element::macro('titleModal', fn() => $this->class('text-xl font-medium'));
\Kompo\Elements\Element::macro('titleAccent', fn() => $this->class('text-lg font-medium text-info'));
\Kompo\Elements\Element::macro('titleMini', fn() => $this->class('text-sm uppercase leading-widest font-semibold'));
\Kompo\Elements\Element::macro('titleMiniStandard', fn() => $this->class('text-medium font-semibold'));
\Kompo\Elements\Element::macro('titleStat', fn() => $this->class('text-2xl font-black'));

if(!function_exists('_TitleMain')) {
    function _TitleMain($label = '')
    {
        return _Html($label)->titleMain();
    }
}

if(!function_exists('_TitleCard')) {
    function _TitleCard($label = '')
    {
        return _Html($label)->titleCard();
    }
}

if(!function_exists('_TitleModal')) {
    function _TitleModal($label = '', $icon = null)
    {
        if (!$label && !$icon) {
            return;
        }

        return _Html($label)->titleModal()
            ->icon(_ModalIcon($icon))
            ->class('flex items-center');
    }
}

if(!function_exists('_TitleAccent')) {
    function _TitleAccent($label = '')
    {
        return _Html($label)->titleAccent()->class('mb-2');
    }
}

if(!function_exists('_TitleMini')) {
    function _TitleMini($label)
    {
        return _Html($label)->titleMini();
    }
}

if(!function_exists('_TitleMiniWhite')) {
    function _TitleMiniWhite($label)
    {
        return _Html($label)->titleMini()->class('text-white');
    }
}

if(!function_exists('_TitleMiniStandard')) {
    function _TitleMiniStandard($label)
    {
        return _Html($label)->titleMiniStandard();
    }
}

/* LABELS AND VALUES */
if(!function_exists('_LabelMiniValue')) {
    function _LabelMiniValue($label = '', $value = null)
    {
        $value = $value instanceof \Kompo\Elements\Element ? $value : _Html($value);

        return _Rows(
            _Html($label)->class('text-sm font-medium'),
            $value,
        );
    }
}

/* DESCRIPTIONS AND OTHER */
\Kompo\Elements\Element::macro('textSm', fn() => $this->class('text-sm'));
\Kompo\Elements\Element::macro('textSmGray', fn() => $this->textSm()->class('opacity-60'));

if(!function_exists('_TextSm')) {
    function _TextSm($label = '')
    {
        return _Html($label)->textSm();
    }
}

if(!function_exists('_TextSmGray')) {
    function _TextSmGray($label = '')
    {
        return _Html($label)->textSmGray()->class('opacity-80');
    }
}

/* Yes No Elements */
if(!function_exists('_HtmlYesNo')) {
    function _HtmlYesNo($value)
    {
        return _Html($value ? __('Yes') : __('No'));
    }
}

/* Pill Elements */
\Kompo\Elements\Element::macro('asPill', fn($colorClass = '') => $this->class('text-xs font-medium px-4 py-1 rounded-full inline-block')->class($colorClass));
\Kompo\Elements\Element::macro('asPillGrayWhite', fn() => $this->asPill('bg-level5 border !py-2 !px-6'));

if(!function_exists('_Pill')) {
    function _Pill($label = '')
    {
        return _Html($label)->asPill();
    }
}

if(!function_exists('_Pill3')) {
    function _Pill3($label = '')
    {
        return _Pill($label)->class('bg-level3 text-greenmain');
    }
}
