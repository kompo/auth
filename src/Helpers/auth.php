<?php

if (!function_exists('_LinkAlreadyHaveAccount')) {
    function _LinkAlreadyHaveAccount()
    {
        return _Link('auth.i-already-have-an-account-log-in-instead')->class('text-sm text-gray-600 self-center')->href('login');
    }
}

if (!function_exists('_CheckboxTerms')) {
    function _CheckboxTerms()
    {
        return _Checkbox(__('register.i-agree-to').' '.'<a href="'.route('privacy').'" class="underline" target="_blank">'.__('register.the-terms').'</a>')
            ->name('terms', false);
    }
}

// RULES
if (!function_exists('registerRules')) {
    function registerRules()
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => passwordRules(),
            'terms' => ['required', 'accepted'],
        ];
    }
}

if (!function_exists('passwordRules')) {
    function passwordRules()
    {
        $passwordRules = new \Laravel\Fortify\Rules\Password();

        return ['required', 'string', $passwordRules->requireUppercase()->requireNumeric()->requireSpecialCharacter(), 'confirmed'];
    }
}

if (!function_exists('baseEmailRules')) {
    function baseEmailRules()
    {
        return ['required', 'string', 'email', 'max:255'];
    }
}