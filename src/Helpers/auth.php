<?php

use Kompo\Auth\Models\Teams\TeamRole;

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
        'password' => passwordRules(),
        'terms' => ['required', 'accepted'],
    ];
}

function passwordRules()
{
	$passwordRules = new \Laravel\Fortify\Rules\Password();

    return ['required', 'string', $passwordRules->requireUppercase()->requireNumeric()->requireSpecialCharacter(), 'confirmed'];
}

function baseEmailRules()
{
    return ['required', 'string', 'email', 'max:255'];
}

/** Current Team, Roles, etc */
function currentTeam() 
{
    if (!auth()->user()) {
        return;
    }

    if (!auth()->user()->current_team_id) {
        auth()->user()->switchToFirstTeam();
    }

    return \Cache::remember('currentTeam'.auth()->id(), 120,
        fn() => auth()->user()->currentTeam
    );
}

function refreshCurrentTeam()
{
    \Cache::put('currentTeam'.auth()->id(), auth()->user()->currentTeam, 120);
}

function currentTeamId() 
{
    if (!auth()->user()) {
        return;
    }

    if (!auth()->user()->current_team_id) {
        auth()->user()->switchToFirstTeam();
    }

    return auth()->user()->current_team_id;
}