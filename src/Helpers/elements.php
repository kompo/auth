<?php

use Kompo\Auth\Elements\Collapsible;
use Kompo\Auth\Elements\ResponsiveTabs;

function _Video($src)
{
    return _Html('<video controls src="' . $src . '"></video>');
}

function _Vid($src)
{
    return _Video($src);
}

function _Audio($src)
{
    return _Html('<audio controls src="' . $src . '"></audio>');
}

function _Aud($src)
{
    return _Audio($src);
}

$checkboxCache = [];

// function

function _CheckboxMultipleStates($name, $values = [], $colors = [], $default = null)
{
    $values = collect($values)->prepend(null);
    $colors = collect($colors)->prepend(null);

    $parsedOptions = collect([]);

    for ($i = 0; $i < count($values); $i++) {
        $nextIndex = $i + 1 == $values->count() ? 0 : $i + 1;
        $nextValue = $values->get($nextIndex);

        $value = $values->get($i);

        $parsedOptions->put($nextValue ?: 0, _Html()->class($name)->class('border border-black rounded w-4 h-4')
            ->class($colors->get($i) ?: '')
            ->when($default == $value, fn($el) => $el->class('perm-selected'))
            ->when($default != $value, fn($el) => $el->class('hidden')));
    }

    return _LinkGroup()->name($name, false)->options($parsedOptions->toArray())
        ->containerClass('')->selectedClass('x', '')
        ->when($default, fn($el) => $el->default($default))
        ->onChange(fn($e) => $e->run('() => {changeLinkGroupColor("'. $name .'")}'));

}

\Kompo\Tabs::macro('holdActualTab', function() {
    return $this->run(getPushParameterFn('tab_number', 'getActualTab("' . ($this->id ?: '') . '")', true))
            ->activeTab(request('tab_number') ?: 0);
});

if (!function_exists('_Collapsible')) {
    function _Collapsible() {
        return Collapsible::form(...func_get_args());
    }
}

if (!function_exists('_ResponsiveTabs')) {
    /**
     * Tabs element with a select dropdown for mobile.
     * @param array  $tabs
     * @param deprecated $tabsClass Use the ->tabsClass() method instead
     * @param deprecated $tabsCommonClass Use the ->tabsCommonClass() method instead
     * @param deprecated $tabsSelectedClass Use the ->tabsSelectedClass() method instead
     * @param deprecated $callback Use the methods in chain instead
     * @param deprecated string $breakpoint Use the ->breakpoint() method instead
     */
    function _ResponsiveTabs($tabs, $tabsClass = null, $tabsCommonClass = null, $tabsSelectedClass = null, $callback = null, $breakpoint = 'md')
    {
        return ResponsiveTabs::form(...$tabs)
            ->tabsClass($tabsClass)
            ->tabsCommonClass($tabsCommonClass)
            ->tabsSelectedClass($tabsSelectedClass)
            ->breakpoint($breakpoint)
            ->tabsCallbackDecoration($callback);
    }
}
