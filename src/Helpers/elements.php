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

function _CheckboxMultipleStates($name, $values = [], $colors = [])
{
    $values = collect([null])->merge($values);
    $colors = collect([null])->merge($colors);

    $parsedOptions = collect([]);

    for ($i = 0; $i < count($values); $i++) {
        $nextIndex = $i + 1 == $values->count() ? 0 : $i + 1;
        $value = $values->get($nextIndex);

        $parsedOptions->put($value ?: 0, _Html()->class($name)->class('border border-black rounded w-4 h-4')
            ->when($i == 0, fn($el) => $el->class('perm-selected'))
            ->when($i !== 0, fn($el) => $el->class('hidden')->class($colors->get($i))));
    }

    return _LinkGroup()->name($name, false)->options($parsedOptions->toArray())
    ->containerClass('')->selectedClass('x', '')
    ->default(null)
    ->run('() => {changeLinkGroupColor("'. $name .'")}');

}