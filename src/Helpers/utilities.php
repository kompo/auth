<?php

use \Kompo\Elements\Element;
use Kompo\Interactions\Action;
use Kompo\Interactions\Interaction;

Kompo\Elements\Layout::macro('applyToAllElements', function ($callback, $exclude = []) {
	$this->elements = collect($this->elements)->map(function ($el, $i) use ($callback, $exclude) {
		if (!in_array($i, $exclude)) {
			return $callback($el);
		}

		return $el;
	})->all();

	return $this;
});

Kompo\Elements\Layout::macro('stopPropagation', function () {
	return $this->attr([
		'onclick' => 'event.stopPropagation()',
	]);
});

Kompo\Elements\Trigger::macro('panelLoading', function ($id) {
	Interaction::appendToWithAction($this, new Action($this, 'run', ['() => {
		const panel = document.getElementById("' . $id . '");

		if(panel) {
			panel.innerHTML = "<div></div>" + panel.innerHTML;
			panel.classList.add("vlPanelLoading");
		}
	}']));

	return $this;
});

/* Transformers */
if (!function_exists('tinyintToBool')) {
	function tinyintToBool($value): string
	{
		return $value == 1 ? 'Yes' : 'No';
	}
}

if (!function_exists('toRounded')) {
	function toRounded($value, $decimals = 2): string
	{
		return round($value, $decimals);
	}
}

if (!function_exists('getFullName')) {
	function getFullName($firstName, $lastName): string
	{
		return collect([$firstName, $lastName])->filter()->implode(' ');
	}
}

if (!function_exists('guessFirstName')) {
	function guessFirstName($fullName)
	{
		$names = explodeName($fullName);
		return count($names) == 1 ? '' : $names[0];
	}
}

if (!function_exists('guessLastName')) {
	function guessLastName($fullName)
	{
		$names = explodeName($fullName);
		return count($names) == 1 ? $names[0] : $names[1];
	}
}

if (!function_exists('explodeName')) {
	function explodeName($fullName)
	{
		return explode(' ', $fullName, 2);
	}
}

if (!function_exists('getAgeFromDob')) {
	function getAgeFromDob($dateOfBirth): string
	{
		if (!$dateOfBirth) {
			return '';
		}

		return carbonNow()->diffInYears(carbon($dateOfBirth));
	}
}

function sizeAsKb($size)
{
	return round($size / 1024, 2) . ' KB';
}

/* GENERAL KOMPO */
if (!function_exists('isKompoEl')) {
	function isKompoEl($el)
	{
		return $el instanceof Element;
	}
}

if (!function_exists('getAppClass')) {
	function getAppClass($namespaceInApp, $defaultNamespace = null)
	{
		if (class_exists($namespaceInApp)) {
			return $namespaceInApp;
		}

		return $defaultNamespace;
	}
}
if (!function_exists('all_class_uses')) {
	function all_class_uses($model)
	{
		$class = new ReflectionClass($model);
		$traits = $class->getTraits();
		while($parent = $class->getParentClass()) {
			$traits += $class->getTraits();
			$class = $parent;
		}
		return array_combine(array_keys($traits), array_keys($traits));
	}
}

/* URIS */
if (!function_exists('getPushParameterFn')) {
	function getPushParameterFn($parameter, $value, $valueInJs = false)
	{
		$getActualTabFn = <<<javascript
			function getActualTab(id = null)
			{
				return [...document.querySelector((id ? '#' + id + ' ' : '') + ".vlTabContent").children].findIndex((c) => !c.getAttribute("aria-hidden"));
			}
		javascript;

		$regexParam = "/(&|\?)$parameter=[^&]*/";

		if ($valueInJs) {
			$value = '${' . $value . '}';
		}

		$fn = '() => {' . $getActualTabFn . ' const hrefWithoutActualParam = location.href.replace(' . $regexParam . ', ""); const charToAppend = hrefWithoutActualParam.indexOf("?") == -1 ? "?" : "&"; window.history.pushState(null, null, `${hrefWithoutActualParam}${charToAppend}'
			. $parameter . '=' . $value . '`);}';

		return $fn;
	}
}

if (!function_exists('createRandomNumber')) {
	function createRandomNumber($max)
	{
		return random_int(0, $max - 1);
	}
}

if (!function_exists('getRandStringForModel')) {

	function getRandStringForModel($model, $colName, $length = 9)
	{
		if(!$model->$colName) {
			$code = '';
			do {
				$code = \Str::random($length);
			} while($model::class::where($colName, $code)->first());
	
			return $code;
		}
	
		return $model->$colName;
	}
}

if (!function_exists('objectToArray')) {
	function objectToArray($object)
	{
		return json_decode(json_encode($object), true);
	}
}
