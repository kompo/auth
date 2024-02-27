<?php

/* MAP INPUTS */
if (!function_exists('_CustomPlace')) {
    function _CustomPlace($label = 'crm.contact.address', $name = 'address1')
    {
        return _Place($label)->name($name)->placeholder('123 Main Street')
            ->editableWith(_Link('general.edit')->rIcon(_Sax('edit'))->class('text-xs text-gray-600')->post('edit-place-fields')->inModal())
            ->formattedLabel('address_label')
            ->attributesToColumns()
            ->noAutocomplete()
            ->defaultCenter(45.5017, -73.5673)
            ->noDefaultUi()
            ->componentRestrictions([
                'country' => ['ca']
            ]);
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