<?php

use \Kompo\Elements\Element;

const SAX_ICON_MAP = 'location';

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
            ->placeholder('inscriptions.123-Main-Street')
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

if (!function_exists('loadFormattedLabel')) {
    function loadFormattedLabel($address)
    {
        if (!$address) {
            return;
        }

        $address->address_label = $address?->getAddressInline(); //todo remove after changing Kompo to autoload if formattedLabel

        return $address;
    }
}

