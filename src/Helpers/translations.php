<?php 

if(!function_exists('translationsArr')) {
    function translationsArr($name)
    {
        return collect(config('kompo.locales'))->mapWithKeys(fn($locale, $key) => [$key => __($name, [], $key)]);
    }
}

if(!function_exists('translationsStr')) {
    function translationsStr($name)
    {
        return json_encode(translationsArr($name));
    }
}

/* LOCALE ELEMENTS */
if(!function_exists('_LocaleSwitcher')) {
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
}