<?php 

function _Modal()
{
	return _Rows(
		...func_get_args()
	)->class('overflow-y-auto mini-scroll')
	->style('max-height:95vh;min-width:350px');
}

function _ModalBody()
{
	return _Rows(
		...func_get_args(),
	)->class('p-6');
}

/* TODO REVIEW THESE BROUGHT THEM FROM ADF */
function _ModalHeader($title, $headerButtons = null)
{
    return _FlexBetween(

        $title,

        _FlexEnd(
            $headerButtons
        )->class('flex-row-reverse md:flex-row md:ml-8')
    )
    ->class('px-8 pt-6 pb-4 rounded-t-2xl')
    ->class('flex-col items-start md:flex-row md:items-center')
    ->alignStart();
}

function _ModalTitle($title, $icon = null)
{
    if (!$title && !$icon) {
        return;
    }

    return _Title($title)
        ->icon(_ModalIcon($icon))
        ->class('text-2xl sm:text-3xl font-semibold text-info')
        ->class('mb-4 md:mb-0')
        ->class('flex items-center');
}


function _ModalIcon($icon)
{
    return !$icon ? null : _Sax($icon, 32)->class('mr-1');
}