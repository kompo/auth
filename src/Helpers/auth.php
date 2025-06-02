<?php

use Condoedge\Utils\Kompo\Elements\ValidatedInput;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Date;
use Kompo\Elements\Field;
use Kompo\Place;

function checkAuthPermission($id, $type = PermissionTypeEnum::READ, $specificTeamId = null) {
    if (globalSecurityBypass()) {
        return true;
    }

    return !Permission::findByKey($id) || auth()->user()?->hasPermission($id, $type, $specificTeamId);
}

if (!function_exists('globalSecurityBypass')) {
    /**
     * Checks if security should be globally bypassed.
     * Uses the service container to get the security-bypass service.
     * 
     * @return bool True if security should be bypassed
     */
    function globalSecurityBypass() 
    {
        if (app()->bound('kompo-auth.security-bypass')) {
            return app()->make('kompo-auth.security-bypass')();
        }
        
        return config('kompo-auth.security.bypass-security', false);
    }
}

if (!function_exists('bypassSecurityInThisRunningContext')) {
    function bypassSecurityInThisRunningContext() 
    {
        app()->bind('kompo-auth.security-bypass', function ($app) {
            return function () {
                return true;
            };
        });
    }
}

\Kompo\Elements\BaseElement::macro('checkAuth', function ($id, $type = PermissionTypeEnum::READ, $specificTeamId = null, $returnNullInstead = false) {
    if (globalSecurityBypass()) {
        return $this;
    }

    if(checkAuthPermission($id, $type, $specificTeamId)) {
        return $this;
    }

    if ($returnNullInstead) {
        return null;
    }

    // TODO: It might be that we'll need to return a null element here, to avoid rendering the element.
    return (new ($this::class))->class('hidden');
});

\Kompo\Elements\BaseElement::macro('checkAuthWrite', function ($id, $specificTeamId = null, $returnNullInstead = false) {
    return $this->checkAuth($id, PermissionTypeEnum::WRITE, $specificTeamId, $returnNullInstead);
});

Field::macro('readOnlyIfNotAuth', function ($id, $specificTeamId = null) {
    if (globalSecurityBypass()) {
        return $this;
    }

    if(checkAuthPermission($id, PermissionTypeEnum::WRITE, $specificTeamId)) {
        return $this;
    }

    return $this->readOnly()->disabled()->class('!opacity-60');
});

\Kompo\Html::macro('hashIfNotAuth', function ($id, $specificTeamId = null, $minChars = 12) {
    if (globalSecurityBypass()) {
        return $this;
    }

    if(checkAuthPermission($id, PermissionTypeEnum::READ, $specificTeamId)) {
        return $this;
    }

    $this->label = str_pad(preg_replace('/.*/', '*', $this->label), $minChars, '*');

    return $this;
});

Field::macro('hashIfNotAuth', function ($id, $specificTeamId = null, $minChars = 12) {
    if (globalSecurityBypass()) {
        return $this;
    }

    if(checkAuthPermission($id, PermissionTypeEnum::READ, $specificTeamId)) {
        return $this;
    }

    $this->value = str_pad(preg_replace('/.*/', '*', $this->value), $minChars, '*');

    $classesThatRequiresInputInstance = [Place::class, ValidatedInput::class, Date::class];

    foreach ($classesThatRequiresInputInstance as $class) {
        if ($this instanceof $class) {
            return _Input($this->label)->value($this->value)->name('restricted_input', false);
        }
    }


    return $this;
});

Field::macro('hashAndReadOnlyIfNotAuth', function ($id, $specificTeamId = null, $minChars = 12) {
    return $this->hashIfNotAuth("{$id}.sensibleColumns", $specificTeamId, minChars: $minChars)->readOnlyIfNotAuth($id, $specificTeamId)->readOnlyIfNotAuth("{$id}.sensibleColumns", $specificTeamId);
});

if (!function_exists('_LinkAlreadyHaveAccount')) {
    function _LinkAlreadyHaveAccount()
    {
        return _Link('auth-i-already-have-an-account-log-in-instead')->class('text-sm text-gray-600 self-center')->href('login');
    }
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


// RULES
if (!function_exists('registerRules')) {
    function registerRules()
    {
        return array_merge(namesRules(), [
            'password' => passwordRules(),
            'terms' => ['required', 'accepted'],
        ]);
    }
}

if (!function_exists('namesRules')) {
    function namesRules()
    {
        return config('kompo-auth.register_with_first_last_name') ? [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
        ] : [
            'name' => ['required', 'string', 'max:255'],
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

/* ACTIONS */
if(!function_exists('fireRegisteredEvent')) {
    function fireRegisteredEvent($user)
    {
        //event(new \Illuminate\Auth\Events\Registered($user)); //uncomment if needed
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

if(!function_exists('currentPermissions')) {
    function currentPermissions() 
    {
        if (!auth()->user()) {
            return;
        }

        if (!auth()->user()->current_team_role_id) {
            auth()->user()->switchToFirstTeamRole();
        }

        return \Cache::rememberWithTags(['permissions'], 'currentPermissions'.auth()->id(), 120,
            fn() => auth()->user()->currentTeamRole->permissions()->pluck('complex_permission_key')
        );
    }
}

if(!function_exists('currentTeam')) {
    /**
     * @return \Kompo\Auth\Models\Teams\Team|null The current team
     */
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

if(!function_exists('currentTeamId')) {
    function currentTeamId() 
    {
        if (!auth()->user()) {
            return;
        }
        return currentTeamRole()->team_id;
    }
}

if (!function_exists('isSuperAdmin')) {
    function isAppSuperAdmin()
    {
        return auth()->user() && auth()->user()->hasAccessToTeam(1, 'super-admin');
    }
}