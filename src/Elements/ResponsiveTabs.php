<?php

namespace Kompo\Auth\Elements;

use Kompo\Rows;

class ResponsiveTabs extends Rows
{
    public $vueComponent = 'Rows';

    protected $tabLabels;
    protected $uniqueId;

    protected $breakpoint;

    protected $tabsClass;
    protected $tabsSelectedClass;
    protected $tabsCommonClass;

    protected $tabsCallbackDecoration;

    public static $breakpoints = [
        'sm' => 'sm:block sm:hidden',
        'md' => 'md:block md:hidden',
        'lg' => 'lg:block lg:hidden',
        'xl' => 'xl:block xl:hidden',
        '2xl' => '2xl:block 2xl:hidden',
    ];

    public function __construct(...$args)
    {
        parent::__construct(...$args);

        $this->breakpoint('md');

        $this->tabLabels = collect($this->elements)->map(fn ($tab) => $tab?->label)->filter();
        $this->uniqueId = uniqid();
    }

    public function mounted()
    {
        $this->elements = [
            $this->selectTabs(),
            $this->tabsDecorated(),
        ];
    }

    public function breakpoint(string $breakpoint)
    {
        $this->breakpoint = $breakpoint;

        return $this;
    }

    public function tabsClass(string|null $tabsClass)
    {
        $this->tabsClass = $tabsClass;

        return $this;
    }

    public function tabsSelectedClass(string|null $tabsSelectedClass)
    {
        $this->tabsSelectedClass = $tabsSelectedClass;

        return $this;
    }

    public function tabsCommonClass(string|null $tabsCommonClass)
    {
        $this->tabsCommonClass = $tabsCommonClass;

        return $this;
    }

    public function tabsCallbackDecoration($tabsCallbackDecoration)
    {
        $this->tabsCallbackDecoration = $tabsCallbackDecoration;

        return $this;
    }

    protected function tabsDecorated()
    {
        $callback = $this->tabsCallbackDecoration;

        return _Tabs(...$this->elements)
            ->commonClass("hidden {$this->breakpoint}:block mr-8")
            ->when($this->tabsClass, fn ($el) => $el->class($this->tabsClass))
            ->when($this->tabsCommonClass, fn ($el) => $el->commonClass($this->tabsCommonClass . " hidden {$this->breakpoint}:block"))
            ->when($this->tabsSelectedClass, fn ($el) => $el->selectedClass($this->tabsSelectedClass))
            ->id('responsive-tabs-' . $this->uniqueId)
            ->holdActualTab()
            ->when($callback && is_callable($callback), fn ($el) => $callback($el));
    }

    protected function selectTabs()
    {
        return _Select()
            ->placeholder($this->tabLabels[0])
            ->id('tabs-select-' . $this->uniqueId)
            ->name('tabs_select', false)
            ->class("block {$this->breakpoint}:hidden  2xl:hidden without-x-icon whiteField")
            ->attr([
                'readonly' => 'readonly',
            ])
            ->options($this->tabLabels)
            ->value(request('tab_number') ?: 0)
            ->onChange(
                fn ($e) => $e
                    ->run($this->jsSelect())
            );
    }

    protected function jsSelect()
    {
        return '() => {
            function activateTabFromSelect(id)
            {
                let tabOptions = $(`#tabs-select-${id}`).closest(".vlInputWrapper")[0].querySelectorAll(".vlOption");
                let tabIndex = [...tabOptions].findIndex(option => option.classList.contains("vlSelected"));

                if (tabIndex == -1) {
                    tabOptions[0].click();
                    return 0;
                }

                let tabs = document.querySelectorAll(`#responsive-tabs-${id} a[role=tab]`);

                [...tabs][tabIndex >= 0 ? tabIndex : 0].click();

                return tabIndex;
            }

            let tabIndex = activateTabFromSelect("' . $this->uniqueId . '");

            (
                ' . getPushParameterFn('tab_number', 'tabIndex', true) . '
            )()
        }';
    }
}
