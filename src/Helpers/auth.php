<?php

function _LinkAlreadyHaveAccount()
{
	return _Link('auth.i-already-have-an-account-log-in-instead')->class('text-sm text-gray-600 self-center')->href('login');
}

function _CheckboxTerms()
{
	return _Checkbox(__('register.i-agree-to').' '.'<a href="'.route('privacy').'" class="underline" target="_blank">'.__('register.the-terms').'</a>')
        ->name('terms', false);
}

/* RULES */
function registerRules()
{
	return [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
        'password' => passwordRule(),
        'terms' => ['required', 'accepted'],
    ];
}

function passwordRule()
{
	$passwordRules = new \Laravel\Fortify\Rules\Password();

    return ['required', 'string', $passwordRules->requireUppercase()->requireNumeric()->requireSpecialCharacter(), 'confirmed'];
}