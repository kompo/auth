<?php

use \Kompo\Elements\Element;

/* Transformers */
if(!function_exists('tinyintToBool')) {
	function tinyintToBool($value): string
	{
		return $value == 1 ? 'Yes' : 'No';
	}
}

if(!function_exists('toRounded')) {
	function toRounded($value, $decimals = 2): string
	{
		return round($value, $decimals);
	}
}

if(!function_exists('getFullName')) {
	function getFullName($firstName, $lastName): string
	{
		return collect([$firstName, $lastName])->filter()->implode(' ');
	}
}

if(!function_exists('getAgeFromDob')) {
	function getAgeFromDob($dateOfBirth): string
	{
		if (!$dateOfBirth) {
			return '';
		}

		return carbonNow()->diffInYears(carbon($dateOfBirth)).' '.__('general-years');
	}
}

/* GENERAL KOMPO */
if(!function_exists('isKompoEl')) {
	function isKompoEl($el)
	{
		return $el instanceof Element;
	}
}


