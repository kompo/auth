<?php

use Kompo\Auth\Inputs\NumberRange;
use Kompo\Select;

Select::macro('overModal', function ($id) {
	return $this->id($id)
	->class('select-over-modal')
	->onFocus(fn($e) => $e->run('() => {
		let input =  $("#'. $id .'").closest(".vlTaggableInput");
		let inputWidth = input.width();
		let inputOffset = input.offset();
		let inputHeight = input.height();
		const dropdown = $("#'. $id .'").closest(".vlInputWrapper").find(".vlOptions");

		let style = dropdown.attr("style") || "";
		style += "transform: translateY(" + (inputOffset.top + inputHeight) + "px) !important;";
		style += "width:" + inputWidth + "px !important;";

		dropdown.attr("style", style);
	}'));
});

if (!function_exists('_ColorPicker')) {
	function _ColorPicker($label = '')
	{
		return _Input($label)->type('color');
	}
}

if (!function_exists('_InputEmail')) {
	function _InputEmail($label = '')
	{
		return _Input($label)->type('email');
	}
}
if (!function_exists('_NumberRange')) {
	function _NumberRange()
	{
		return NumberRange::form();
	}
}