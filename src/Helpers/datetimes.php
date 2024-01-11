<?php 

use Illuminate\Support\Carbon;

function toDatetime($date)
{
	return carbon($date)->translatedFormat('d M Y H:i');
}

function appendSecondsToDatetime($dateTime, $seconds)
{
	return carbonDateTime($dateTime)->addSeconds($seconds)->format('Y-m-d H:i:s');
}

function carbonDate($dateOrString, $format = 'Y-m-d')
{
    return is_string($dateOrString) ? createCarbonFromFormat($dateOrString, $format, 10) : createCarbonFromDatetime($dateOrString);
}

function carbonDateTime($dateOrString, $format = 'Y-m-d H:i')
{
    return is_string($dateOrString) ? createCarbonFromFormat($dateOrString, $format, 16) : createCarbonFromDatetime($dateOrString);
}

function createCarbonFromFormat($dateString, $format, $length)
{
	return Carbon::createFromFormat($format, substr($dateString, 0, $length));
}

function createCarbonFromDatetime($datetime)
{
	return $datetime instanceOf \Datetime ? Carbon::instance($datetime) : $datetime;
}

/* ELEMENTS */
function _HtmlDate($dateStr)
{
	return _Html(carbonDate($dateStr)?->translatedFormat('d M Y'));
}

function _HtmlTime($dateStr)
{
	return _Html(carbonDateTime($dateStr)?->translatedFormat('H:i'));
}

function _HtmlTimeRange($startDate, $endDate)
{
	return _Html($startDate->format('H:i').'<br>'.$endDate->format('H:i'));
}