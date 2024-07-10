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

if(!function_exists('guessFirstName')) {
	function guessFirstName($fullName)
	{
		$names = explodeName($fullName);
		return count($names) == 1 ? '' : $names[0];
	}
}

if(!function_exists('guessLastName')) {
	function guessLastName($fullName)
	{
		$names = explodeName($fullName);
		return count($names) == 1 ? $names[0] : $names[1];
	}
}

if(!function_exists('explodeName')) {
	function explodeName($fullName)
	{
		return explode(' ', $fullName, 2);
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

function sizeAsKb($size)
{
	return round($size / 1024, 2).' KB';
}

/* GENERAL KOMPO */
if(!function_exists('isKompoEl')) {
	function isKompoEl($el)
	{
		return $el instanceof Element;
	}
}


