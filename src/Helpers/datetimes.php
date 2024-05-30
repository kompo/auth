<?php 

use Illuminate\Support\Carbon;

if (!function_exists("carbonNow")) {
	function carbonNow()
	{
		return carbon(date('Y-m-d'));
	}
}

if (!function_exists("toDatetime")) {
	function toDatetime($date)
	{
		return carbon($date)->translatedFormat('d M Y H:i');
	}
}

if (!function_exists("appendSecondsToDatetime")) {
	function appendSecondsToDatetime($dateTime, $seconds)
	{
		return carbonDateTime($dateTime)->addSeconds($seconds)->format('Y-m-d H:i:s');
	}
}

if (!function_exists("carbonDate")) {
	function carbonDate($dateOrString, $format = 'Y-m-d')
	{
		return is_string($dateOrString) ? createCarbonFromFormat($dateOrString, $format, 10) : createCarbonFromDatetime($dateOrString);
	}
}

if (!function_exists("carbonDateTime")) {
	function carbonDateTime($dateOrString, $format = 'Y-m-d H:i')
	{
		return is_string($dateOrString) ? createCarbonFromFormat($dateOrString, $format, 16) : createCarbonFromDatetime($dateOrString);
	}
}

if (!function_exists("createCarbonFromFormat")) {
	function createCarbonFromFormat($dateString, $format, $length)
	{
		return Carbon::createFromFormat($format, substr($dateString, 0, $length));
	}
}

if (!function_exists("createCarbonFromDatetime")) {
	function createCarbonFromDatetime($datetime)
	{
		return $datetime instanceOf \Datetime ? Carbon::instance($datetime) : $datetime;
	}
}

/* ELEMENTS */
if (!function_exists("_HtmlDate")) {
	function _HtmlDate($dateStr)
	{
		return _Html(carbonDate($dateStr)?->translatedFormat('d M Y'));
	}
}

if (!function_exists("_HtmlDateTime")) {
	function _HtmlDateTime($dateStr)
	{
		return _Html(carbonDateTime($dateStr)?->translatedFormat('d M Y H:i'));
	}
}

if (!function_exists("_HtmlTime")) {
	function _HtmlTime($dateStr)
	{
		return _Html(carbonDateTime($dateStr)?->translatedFormat('H:i'));
	}
}

if (!function_exists("_HtmlTimeRange")) {
	function _HtmlTimeRange($startDate, $endDate)
	{
		return _Html($startDate->format('H:i').'<br>'.$endDate->format('H:i'));
	}
}