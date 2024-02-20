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
function currentTeamRoleId() 
{
    if (!auth()->user()) {
        return;
    }

    if (!auth()->user()->current_team_role_id) {
        auth()->user()->switchToFirstTeamRole();
    }

    return auth()->user()->current_team_role_id;
}

function currentTeamRole() 
{
    if (!auth()->user()) {
        return;
    }

    if (!auth()->user()->current_team_role_id) {
        auth()->user()->switchToFirstTeamRole();
    }

    return \Cache::remember('currentTeamRole'.auth()->id(), 120,
        fn() => auth()->user()->currentTeamRole
    );
}

function currentTeam() 
{
    if (!auth()->user()) {
        return;
    }

    return \Cache::remember('currentTeam'.auth()->id(), 120,
        fn() => currentTeamRole()->team
    );
}

function refreshCurrentTeamAndRole()
{
    \Cache::put('currentTeamRole'.auth()->id(), auth()->user()->currentTeamRole, 120);
    \Cache::put('currentTeam'.auth()->id(), currentTeamRole()->team, 120);
}

function currentTeamId() 
{
    if (!auth()->user()) {
        return;
    }

    return currentTeamRole()->team_id;
}