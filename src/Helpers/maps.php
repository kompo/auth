<?php

use \Kompo\Elements\Element;

/* MAP INPUTS */
Element::macro('editableFields', fn() => $this->editableWith(
    _Link('general.edit')->rIcon(_Sax('edit'))->class('text-xs text-gray-600')->post('edit-place-fields')->inModal()
));

if (!function_exists('_CustomPlace')) {
    function _CustomPlace($label = 'crm.contact.address', $name = 'address1')
    {
        return _Place($label)->name($name)
            ->formattedLabel('address_label')
            ->attributesToColumns()
            ->noAutocomplete()
            ->noDefaultUi();
    }
}

if (!function_exists('_CanadianPlace')) {
    function _CanadianPlace($label = 'crm.contact.address', $name = 'address1')
    {
        return _CustomPlace($label, $name)
            ->placeholder('123 Main Street')
            ->defaultCenter(45.5017, -73.5673)
            ->componentRestrictions([
                'country' => ['ca']
            ]);
    }
}

if (!function_exists('_CustomEditablePlace')) {
    function _CustomEditablePlace($label = 'crm.contact.address', $name = 'address1')
    {
        return _CustomPlace($label, $name)
            ->editableFields();
    }
}



/* PURE MAP */
if (!function_exists('_MapNoInput')) {
    function _MapNoInput($markers)
    {
        if (!$markers || !count($markers)) {
            return;
        }

        return _Place()->addMarkers($markers)->class('place-no-input');
    }
}

/* MAP UTILITIES */
if (!function_exists('mapMarker')) {
    function mapMarker($address, $iconUrl = 'location-add.svg')
    {
        if (!$address) {
            return;
        }

        return [
            'lat' => $address->lat,
            'lng' => $address->lng,
            'icon' => asset($iconUrl), //add the svg in public folder when starting a new project
        ];
    }
}

/* MAP LABELS */
if (!function_exists('_AddressWithIcon')) {
    function _AddressWithIcon($address)
    {
        return _Flex2(
            _Sax('location',20)->class('opacity-30'),
            _Html($address),
        )->mb2();
    }
}