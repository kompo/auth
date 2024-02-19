<?php

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

