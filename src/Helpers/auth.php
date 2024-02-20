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

if(!function_exists('baseEmailRules')) {
    function baseEmailRules()
    {
        return ['required', 'string', 'email', 'max:255'];
    }
}

/** Current Team, Roles, etc */
if(!function_exists('currentTeamRoleId')) {
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
}

if(!function_exists('currentTeamRole')) {
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
}

if(!function_exists('currentTeam')) {
    function currentTeam() 
    {
        if (!auth()->user()) {
            return;
        }

        return \Cache::remember('currentTeam'.auth()->id(), 120,
            fn() => currentTeamRole()->team
        );
    }
}

if(!function_exists('refreshCurrentTeamAndRole')) {
    function refreshCurrentTeamAndRole($user)
    {
        \Cache::put('currentTeamRole'.$user->id, $user->currentTeamRole, 120);
        \Cache::put('currentTeam'.$user->id, $user->currentTeamRole->team, 120);
    }
}

if(!function_exists('currentTeamId')) {
    function currentTeamId() 
    {
        if (!auth()->user()) {
            return;
        }
        return currentTeamRole()->team_id;
    }
}