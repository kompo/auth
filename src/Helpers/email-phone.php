<?php

use \Kompo\Elements\Element;

const SAX_ICON_PHONE = 'mobile';
const SAX_ICON_EMAIL = 'sms';

/* PHONE STUFF */
Element::macro('hrefPhone', fn($phone) => $this->href('tel:'.$phone));

if(!function_exists('_PhoneWithIcon')) {
    function _PhoneWithIcon($phone)
    {
        return _Flex2(
            _Sax(SAX_ICON_PHONE, 20)->class('opacity-30'),
            _Html($phone),
        )->mb2();
    }
}

if(!function_exists('_PhoneButton')) {
    function _PhoneButton($phone)
    {
        if (!$phone) {
            return;
        }

        return _Link2Button()->iconSax(SAX_ICON_PHONE)->hrefPhone($phone)->balloon($phone, 'up-right');
    }
}



/* EMAIL STUFF */
Element::macro('hrefEmail', fn($email) => $this->href('mailto:'.$email));

if(!function_exists('_EmailWithIcon')) {
    function _EmailWithIcon($email)
    {
        return _Flex2(
            _Sax(SAX_ICON_EMAIL, 20)->class('opacity-30'),
            _Html($email),
        )->mb2();
    }
}

if(!function_exists('_EmailButton')) {
    function _EmailButton($email)
    {
        if (!$email) {
            return;
        }

        return _Link2Button()->iconSax(SAX_ICON_EMAIL)->href('mailto:'.$email)->balloon($email, 'up-right');
    }
}