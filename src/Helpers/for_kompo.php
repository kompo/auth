<?php 

function _CheckAllItems()
{
    return _Checkbox()->class('pl-3 pt-4 mb-0')->id('checkall-checkbox')->emit('checkAllItems')->run('checkAllCheckboxes');
}

function _CheckSingleItem($itemId)
{
    return _Checkbox()->class('mb-0 child-checkbox')->emit('checkItemId', ['id' => $itemId]);
}

function _CheckButton($label = '')
{
    return _Button($label)->config(['withCheckedItemIds' => true]);
}

function _InputSearch()
{
    return _Input()->icon(_Sax('search-status-1'))->inputClass('px-6 py-4');
}