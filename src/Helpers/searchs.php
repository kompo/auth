<?php

function _Highlighted($search, $str)
{
    return _Html(
        preg_replace('/' . preg_quote($search, "/") . '/i', "<span class='bg-level4 bg-opacity-50'>\$0</span>", $str)
    );
}


function _SearchResult($search, $title, $description = [])
{
    return _Rows(
        _Highlighted($search, $title)->class('font-semibold'),
        _Rows(
            $description
        )->class('text-sm text-level1')
    )->class('cursor-pointer bg-level5 rounded-xl px-2 md:px-6 py-3 !mb-2 mx-4 hover:bg-level3');
}
