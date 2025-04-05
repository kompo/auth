<?php

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