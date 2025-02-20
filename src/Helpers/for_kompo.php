<?php

if (!function_exists("_CheckAllItems")) {
    function _CheckAllItems()
    {
        return _Checkbox()->class('pl-3 pt-4 mb-0')->id('checkall-checkbox')->emit('checkAllItems')->run('checkAllCheckboxes');
    }
}

if (!function_exists('_CheckSingleItem')) {
    function _CheckSingleItem($itemId)
    {
        return _Checkbox()->class('mb-0 child-checkbox')->emit('checkItemId', ['id' => $itemId]);
    }
}

if (!function_exists('_CheckButton')) {
    function _CheckButton($label = '')
    {
        return _Button($label)->config(['withCheckedItemIds' => true]);
    }
}

if (!function_exists('_InputSearch')) {
    function _InputSearch()
    {
        return _Input()->icon(_Sax('search-normal'))->inputClass('px-6 py-4');
    }
}
