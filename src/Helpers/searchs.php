<?php

if(!function_exists('_Highlighted')) {
    function _Highlighted($search, $str)
    {
        return _Html(
            preg_replace('/' . preg_quote($search, "/") . '/i', "<span class='bg-level4 bg-opacity-50'>\$0</span>", $str)
        );
    }
}


if(!function_exists('_SearchResult')) {
    function _SearchResult($search, $title, $description = [])
    {
        return _Rows(
            _Highlighted($search, $title)->class('font-medium'),
            _Rows(
                $description
            )->class('text-sm text-greenmain')
        )->class('cursor-pointer bg-level4 rounded-xl px-2 md:px-6 py-3 !mb-2 mx-6 hover:bg-level5');
    }
}
