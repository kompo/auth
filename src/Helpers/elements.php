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
        $values[0] => _Rows(
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
        $nextIndex = $i == $values->count() ? 0 : $i + 1;
        $nextValue = $values->get($nextIndex) ?: 0;

        $value = $values->get($i);

        $option = _Html()->class($name)->class('w-4 h-4')
            ->class($colors->get($i) ?: '')
            ->when($default == $value && !is_array($default) , fn($el) => $el->class('perm-selected'))
            ->when($default != $value || is_array($default), fn($el) => $el->class('hidden'));

        $parsedOptions->put($nextValue, $option);
    }

    return _LinkGroup()->name($name, false)->options($parsedOptions->toArray())
        ->containerClass('checkbox-style')->selectedClass('x', '')
        ->when($default && !is_array($default), fn($el) => $el->default($default))
        ->onChange(fn($e) => $e->run('() => {
            changeLinkGroupColor("'. $name .'");
            cleanLinkGroupNullOption("'. $name .'");
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
            _Password('auth-my-password')->name('password'),
            _Password('auth-my-password-confirmation')->name('password_confirmation', false),
        );
    }
}

if (!function_exists('_CheckboxTerms')) {
    function _CheckboxTerms()
    {
        return _Checkbox(__('auth-register-i-agree-to').' '.'<a href="'.url('privacy').'" class="underline" target="_blank">'.__('register.the-terms').'</a>')
            ->name('terms', false);
    }
}