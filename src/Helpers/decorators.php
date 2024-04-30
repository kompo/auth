<?php 

/* SEPARATORS */
if (!function_exists('_SeparatorGray')) {
    function _SeparatorGray()
    {
        return _Html()->class('my-4 border-b border-gray-200');
    }
}
