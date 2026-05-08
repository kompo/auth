<?php

function _CheckboxMultipleStates($name, $values = [], $colors = [], $default = null, $ableToChangeIt = true)
{
    return \Kompo\Auth\Elements\MultiStateCheckbox::form(null)
        ->name($name, false)
        ->mode('single')
        ->values($values)
        ->colors($colors)
        ->readonly(!$ableToChangeIt)
        ->default($default);
}

function _CheckboxSectionMultipleStates($name, $values = [], $colors = [], $default = null, $ableToChangeIt = true)
{
    return \Kompo\Auth\Elements\MultiStateCheckbox::form(null)
        ->name($name, false)
        ->mode('section')
        ->values($values)
        ->colors($colors)
        ->readonly(!$ableToChangeIt)
        ->default($default);
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