<?php
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

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

function _CheckboxMultipleStates($name, $values = [], $colors = [], $default = null)
{
    $values = collect([null])->merge($values);
    $colors = collect([null])->merge($colors);

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
        ->default($default)
        ->onChange(fn($e) => $e->run('() => {changeLinkGroupColor("'. $name .'")}'));

}

\Kompo\Tabs::macro('holdActualTab', function() {
    return $this->run(getPushParameterFn('tab_number', 'getActualTab("' . ($this->id ?: '') . '")', true))
            ->activeTab(request('tab_number') ?: 0);
});


if (!function_exists('_ResponsiveTabs')) {
    function _ResponsiveTabs($tabs, $tabsClass = null, $tabsCommonClass = null, $tabsSelectedClass = null, $callback = null)
    {
        $tabLabels = collect($tabs)->map(fn($tab) => $tab?->label)->filter();
        $uniqueId = uniqid();
    
        $tabElement = _Tabs(...$tabs)
            ->commonClass('hidden md:block mr-8')
            ->when($tabsClass, fn($el) => $el->class($tabsClass))
            ->when($tabsCommonClass, fn($el) => $el->commonClass($tabsCommonClass . ' hidden md:block'))
            ->when($tabsSelectedClass, fn($el) => $el->selectedClass($tabsSelectedClass))
            ->id('responsive-tabs-' . $uniqueId);
    
        if ($callback) {
            $tabElement = $callback($tabElement);
        }
    
        return _Rows(
            _Select()
                ->placeholder($tabLabels[0])
                ->id('tabs-select-' . $uniqueId)
                ->name('tabs_select', false)
                ->class('block md:hidden without-x-icon whiteField')
                ->attr([
                    'readonly' => 'readonly',
                ])
                ->options($tabLabels)
                ->value(request('tab_number') ?: 0)
                ->onChange(fn($e) => $e
                        ->run('() => {
                            let tabIndex = activateTabFromSelect("' . $uniqueId . '");
    
                            (
                                ' . getPushParameterFn('tab_number', 'tabIndex', true) . '
                            )()
                        }')
                ),
            $tabElement->holdActualTab(),
        );
    }
}
