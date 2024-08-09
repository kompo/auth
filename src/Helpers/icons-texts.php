<?php

const SAX_ICON_CALENDAR = 'calendar';

/* COMMON ICON LABELS */
if(!function_exists('_PhoneWithIcon')) {
    function _PhoneWithIcon($phone)
    {
        return _LabelWithIcon(SAX_ICON_PHONE, $phone);
    }
}

if(!function_exists('_EmailWithIcon')) {
    function _EmailWithIcon($email)
    {
        return _LabelWithIcon(SAX_ICON_EMAIL, $email);
    }
}

if (!function_exists('_AddressWithIcon')) {
    function _AddressWithIcon($address)
    {
        return _LabelWithIcon(SAX_ICON_MAP, $address);
    }
}

if (!function_exists('_CalendarWithIcon')) {
    function _CalendarWithIcon($item)
    {
        return _LabelWithIcon(SAX_ICON_CALENDAR, $item);
    }
}

/* EXTRACTED Utilities */
if (!function_exists('_LabelWithIcon')) {
    function _LabelWithIcon($icon, $label)
    {
    	$label = isKompoEl($label) ? $label : _Html($label);

        return _Flex2(
            _Sax($icon, 18)->class('text-greenmain opacity-50'),
            $label,
        )->mb2()->class('!items-start');
    }
}
