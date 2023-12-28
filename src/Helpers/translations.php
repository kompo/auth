<?php 

function translationsArr($name)
{
	return collect(config('kompo.locales'))->mapWithKeys(fn($locale, $key) => [$key => __($name, [], $key)]);
}

function translationsStr($name)
{
	return json_encode(translationsArr($name));
}

/* LOCALE ELEMENTS */
function _LocaleSwitcher()
{
	return _FlexCenter(
        collect(config('kompo.locales'))->map(function ($language, $locale) {
            return _Link(strtoupper($locale))
                ->href('setLocale', ['locale' => $locale])
                ->class(session('kompo_locale') == $locale ? '' : 'text-gray-400')
                ->class('font-medium')->asDropdownLink();
        })
    );
}