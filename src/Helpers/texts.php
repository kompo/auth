<?php

/* TITLES */
\Kompo\Elements\Element::macro('titleMain', fn() => $this->class('text-2xl font-medium'));
\Kompo\Elements\Element::macro('titleCard', fn() => $this->class('text-base font-semibold mb-2'));
\Kompo\Elements\Element::macro('titleModal', fn() => $this->class('text-xl font-semibold'));
\Kompo\Elements\Element::macro('titleAccent', fn() => $this->class('text-lg font-medium text-info'));
\Kompo\Elements\Element::macro('titleMini', fn() => $this->class('text-sm text-info uppercase leading-widest font-bold'));
\Kompo\Elements\Element::macro('titleStat', fn() => $this->class('text-2xl font-black'));

function _TitleMain($label = '')
{
	return _Html($label)->titleMain();
}

function _TitleCard($label = '')
{
	return _Html($label)->titleCard();
}

function _TitleModal($label = '')
{
    return _Html($label)->titleModal();
}

function _TitleAccent($label = '')
{
    return _Html($label)->titleAccent()->class('mb-2');
}

function _TitleMini($label)
{
    return _Html($label)->titleMini();
}

/* LABELS AND VALUES */
function _LabelMiniValue($label = '', $value = null)
{
    $value = $value instanceof \Kompo\Elements\Element ? $value : _Html($value);

    return _Rows(
        _Html($label)->class('text-sm font-medium'),
        $value,
    );
}

/* DESCRIPTIONS AND OTHER */
\Kompo\Elements\Element::macro('textSm', fn() => $this->class('text-sm'));
\Kompo\Elements\Element::macro('textSmGray', fn() => $this->textSm()->class('text-level2'));

function _TextSm($label = '')
{
    return _Html($label)->textSm();
}

function _TextSmGray($label = '')
{
	return _Html($label)->textSmGray();
}

/* Yes No Elements */
function _HtmlYesNo($value)
{
    return _Html($value ? 'Yes' : 'No');
}

/* Pill Elements */
\Kompo\Elements\Element::macro('asPill', fn($colorClass = '') => $this->class('text-xs font-medium px-4 py-1 rounded-full inline-block')->class($colorClass));
\Kompo\Elements\Element::macro('asPillGrayWhite', fn() => $this->asPill('bg-level5 border !py-2 !px-6'));

function _Pill($label = '')
{
    return _Html($label)->asPill();
}

function _Pill3($label = '')
{
    return _Pill($label)->class('bg-level3 text-level1');
}
