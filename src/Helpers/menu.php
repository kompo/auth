<?php

if (!function_exists('_DropdownLink')) {
    function _DropdownLink($label)
    {
        return _Link($label)->asDropdownLink();
    }
}

if (!function_exists('_DropdownDelete')) {
    function _DropdownDelete($label)
    {
        return _DeleteLink($label)->asDropdownLink();
    }
}

\Kompo\Elements\Element::macro('asDropdownLink', function(){
    return $this->class('px-6 py-2')
		->class('whitespace-nowrap'); //controversial
});

if (!function_exists('_Breadcrumbs')) {
    function _Breadcrumbs($backLink, $mainLink)
    {
        return _Flex4(
            $backLink->icon('arrow-left'),
            _Html('|')->class('text-level2 font-thin'),
            $mainLink->class('text-level2 font-medium'),
        )->class('text-2xl md:text-3xl font-bold');
    }
}

if (!function_exists('_BreadcrumbsNew')) {
    function _BreadcrumbsNew()
    {
        $els = func_get_args();

        return _Flex4(
            collect($els)->map(fn($el, $i) => $el->rIcon($i + 1 == count($els) ? null : _Sax('arrow-right-1')))
        )->class('text-level2 mb-4');
    }
}

if (!function_exists('_TripleDotsDropdown')) {
    function _TripleDotsDropdown(...$submenu)
    {
        return _Dropdown()
            ->icon(
                _Svg('dots-vertical')->class('text-xl text-level2')
            )
            ->submenu(
                $submenu
            )
            ->alignUpRight();
    }
}

/* MENU */
if (!function_exists('_SideLink')) {
    function _SideLink($label = '')
    {
        return _Link($label)->class('text-white pl-4 text-sm lg:text-base font-light py-1 px-3 hover:text-level1');
    }
}

if (!function_exists('_SideSection')) {
    function _SideSection(...$rows)
    {
        return _Rows($rows)->class('my-3');
    }
}

if (!function_exists('_SideTitle')) {
    function _SideTitle($label = '')
    {
        return _Html($label)->class('text-xs font-semibold uppercase mb-2 pl-4 text-white opacity-40');
    }
}