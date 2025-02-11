<?php

\Kompo\Link::macro('copyToClipboard', function ($text, $alertMessage = 'auth.copied-to-clipboard') {
    return $this->onClick(fn($e) => $e->run('() => {navigator.clipboard?.writeText("' . $text . '")}') &&
        $e->alert($alertMessage));
});

\Kompo\Rows::macro('copyToClipboard', function ($text, $alertMessage = 'auth.copied-to-clipboard') {
    return $this->onClick(fn($e) => $e->run('() => {navigator.clipboard?.writeText("' . $text . '")}') &&
        $e->alert($alertMessage));
});

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

function _CheckboxSectionMultipleStates($name, $values = [], $colors = [], $default = null)
{
    $values = collect($values);
    $colors = collect($colors);

    $parsedOptions = collect([
        _Rows(
            _Html()->when($default && is_array($default) && !in_array(0, $default), fn($e) => $e->class('hidden') )
                ->class('flex-1 subsection-item value-0'),
            ...$values->map(fn($value, $i) => _Html()->class(
                $colors->get($i) ?? ''
            )->class($default && is_array($default) && in_array($value, $default) ? '' : 'hidden')->class('flex-1 subsection-item'))
        )->class($name)->class('flex flex-row-reverse w-4 h-4')
        ->when(is_array($default) || !$default, fn($el) => $el->class('perm-selected'))
        ->when($default && !is_array($default), fn($el) => $el->class('hidden'))
    ]);

    for ($i = 0; $i < count($values); $i++) {
        $nextIndex = $i + 1 == $values->count() ? 0 : $i + 1;
        $nextValue = $values->get($nextIndex);

        $value = $values->get($i);

        $option = _Html()->class($name)->class('w-4 h-4')
            ->class($colors->get($i) ?: '')
            ->when($default == $value && !is_array($default) , fn($el) => $el->class('perm-selected'))
            ->when($default != $value || is_array($default), fn($el) => $el->class('hidden'));

        $parsedOptions->put($nextValue ?: 0, $option);
    }

    return _LinkGroup()->name($name, false)->options($parsedOptions->toArray())
        ->containerClass('checkbox-style')->selectedClass('x', '')
        ->when($default && !is_array($default), fn($el) => $el->default($default))
        ->onChange(fn($e) => $e->run('() => {
            changeLinkGroupColor("'. $name .'");
            cleanLinkGroupNullOption("'. $name .'");
        }'));
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

if (!function_exists('_ValidatedInput')) {
    function _ValidatedInput()
    {
        return \Kompo\Auth\Elements\ValidatedInput::form(...func_get_args());
    }
}
