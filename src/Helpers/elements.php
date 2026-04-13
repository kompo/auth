<?php

function _CheckboxMultipleStates($name, $values = [], $colors = [], $default = null, $ableToChangeIt = true)
{
    $values = $values instanceof \Illuminate\Support\Collection ? $values->all() : (array) $values;
    $colors = $colors instanceof \Illuminate\Support\Collection ? $colors->all() : (array) $colors;

    $allValues = [null, ...$values];
    $allColors = [null, ...$colors];
    $count = count($allValues);
    $baseClass = $name . ' border border-black rounded w-4 h-4 ';
    $parsedOptions = [];

    for ($i = 0; $i < $count; $i++) {
        $nextValue = ($i + 1 < $count) ? $allValues[$i + 1] : $allValues[0];
        $color = $allColors[$i] ?? '';
        $visibility = ($default == $allValues[$i]) ? ' perm-selected' : ' hidden';

        $parsedOptions[$nextValue ?: 0] = _Html()->class($baseClass . $color . $visibility);
    }

    $el = _LinkGroup()->name($name, false)->options($parsedOptions)
        ->containerClass(($ableToChangeIt ? '' : 'pointer-events-none'))->selectedClass('x', '');

    if ($default) {
        $el->default($default);
    }

    if (!$ableToChangeIt) {
        return $el;
    }

    return $el->onChange(fn($e) => $e->run('() => {changeLinkGroupColor("' . $name . '")}'));
}

function _CheckboxSectionMultipleStates($name, $values = [], $colors = [], $default = null, $ableToChangeIt = true)
{
    $values = $values instanceof \Illuminate\Support\Collection ? $values->all() : (array) $values;
    $colors = $colors instanceof \Illuminate\Support\Collection ? $colors->all() : (array) $colors;

    $isArray = is_array($default);
    $count = count($values);

    // Mixed-state option (multi-colored bars for partial coverage)
    $subItems = [_Html()->class('flex-1 subsection-item value-0' . ($default && $isArray && !in_array(0, $default) ? ' hidden' : ''))];

    for ($i = 0; $i < $count; $i++) {
        $visible = $default && $isArray && in_array($values[$i], $default);
        $subItems[] = _Html()->class(($colors[$i] ?? '') . ' flex-1 subsection-item' . ($visible ? '' : ' hidden'));
    }

    $mixedVisibility = ($isArray || !$default) ? ' perm-selected' : ' hidden';
    $parsedOptions = [
        $values[0] => _Rows(...$subItems)->class($name . ' flex flex-row-reverse w-4 h-4' . $mixedVisibility)
    ];

    // Single-state options
    for ($i = 0; $i < $count; $i++) {
        $nextValue = $values[$i + 1] ?? 0;
        $color = $colors[$i] ?? '';
        $isSelected = !$isArray && $default == $values[$i];

        $parsedOptions[$nextValue] = _Html()->class($name . ' w-4 h-4 ' . $color . ($isSelected ? ' perm-selected' : ' hidden'));
    }

    $el = _LinkGroup()->name($name, false)->options($parsedOptions)
        ->containerClass('checkbox-style ' . ($ableToChangeIt ? '' : 'pointer-events-none'))->selectedClass('x', '');

    if ($default && !$isArray) {
        $el->default($default);
    }

    if (!$ableToChangeIt) {
        return $el;
    }

    return $el->onChange(fn($e) => $e->run('() => {
        changeLinkGroupColor("' . $name . '");
        cleanLinkGroupNullOption("' . $name . '");
    }'));
}

if (!function_exists('_ProfileImg')) {
    function _ProfileImg($user, $sizeClass = 'h-8 w-8')
    {
        if (!$user?->profile_photo_url) {
            return null;
        }

        return _Img($user?->profile_photo_url)
            ->class($sizeClass)
            ->class('rounded-full object-cover border');
    }
}

if (!function_exists('_UserImgDate')) {
    function _UserImgDate($user, $date)
    {
        return _Flex(
            _ProfileImg($user),
            _Rows(
                _Html($user?->name),
                _DiffDate($date),
            )->class('text-xs text-gray-600')
        )->class('space-x-2');
    }
}

/* FIELDS */
if (!function_exists('_InputRegisterNames')) {
    function _InputRegisterNames($defaultName1 = null, $defaultName2 = null)
    {
        return config('kompo-auth.register_with_first_last_name') ? _Rows(
            _Input('auth-your-first-name1')->name('first_name')->default($defaultName1),
            _Input('auth-your-last-name')->name('last_name')->default($defaultName2),
        ) :
            _Input('auth-your-name')->name('name')->default($defaultName1);
    }
}

if (!function_exists('_InputRegisterPasswords')) {
    function _InputRegisterPasswords()
    {
        return _Rows(
            _PasswordInput('auth-my-password')->name('password'),
            _PasswordInput('auth-my-password-confirmation')->name('password_confirmation', false),
        );
    }
}

if (!function_exists('_CheckboxTerms')) {
    function _CheckboxTerms()
    {
        return _Checkbox(__('auth-register-i-agree-to') . ' ' . '<a href="' . url('privacy') . '" class="underline" target="_blank">' . __('register.the-terms') . '</a>')
            ->name('terms', false);
    }
}

if (!function_exists('_StatusNotice')) {
    function _StatusNotice()
    {
        return !session('status') ? null :
            _Html(session('status'))->class('mb-4 p-4 text-sm rounded-2xl StatusNotice');
    }
}