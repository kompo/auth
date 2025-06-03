<?php

use Condoedge\Utils\Kompo\Elements\ValidatedInput;
use Kompo\Auth\Models\Plugins\HasSecurity;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Teams\PermissionResolver;
use Kompo\Date;
use Kompo\Elements\Field;
use Kompo\Place;

/**
 * Optimized permission checking with caching
 */
function checkAuthPermission($id, $type = PermissionTypeEnum::READ, $specificTeamId = null): bool 
{
    if (globalSecurityBypass()) {
        return true;
    }

    if (!auth()->user()) {
        return false;
    }

    // Use the optimized permission resolver
    $resolver = app(PermissionResolver::class);
    return $resolver->userHasPermission(
        auth()->id(),
        $id,
        $type,
        $specificTeamId
    );
}

/**
 * Global security bypass check with caching
 */
if (!function_exists('globalSecurityBypass')) {
    function globalSecurityBypass(): bool 
    {
        $bypass = false;
        
        if (app()->bound('kompo-auth.security-bypass')) {
            $bypass = app()->make('kompo-auth.security-bypass')();
        } else {
            $bypass = config('kompo-auth.security.bypass-security', false);
        }
        
        return $bypass;
    }
}

/**
 * Bypass security for current request context
 */
if (!function_exists('bypassSecurityInThisRunningContext')) {
    function bypassSecurityInThisRunningContext(): void 
    {
        app()->bind('kompo-auth.security-bypass', function ($app) {
            return function () {
                return true;
            };
        });
    }
}

/**
 * Optimized current team role retrieval
 */
if (!function_exists('currentTeamRole')) {
    function currentTeamRole() 
    {
        if (!auth()->user()) {
            return null;
        }

        static $currentTeamRole = null;
        static $lastUserId = null;
        
        // Reset cache if user changed
        if ($lastUserId !== auth()->id()) {
            $currentTeamRole = null;
            $lastUserId = auth()->id();
        }
        
        if ($currentTeamRole === null) {
            if (!auth()->user()->current_team_role_id) {
                auth()->user()->switchToFirstTeamRole();
            }

            $currentTeamRole = \Cache::remember(
                'currentTeamRole' . auth()->id(), 
                900, // 15 minutes
                fn() => auth()->user()->currentTeamRole
            );
        }

        return $currentTeamRole;
    }
}

/**
 * Optimized current team retrieval
 */
if (!function_exists('currentTeam')) {
    function currentTeam() 
    {
        if (!auth()->user()) {
            return null;
        }

        static $currentTeam = null;
        static $lastUserId = null;
        
        // Reset cache if user changed
        if ($lastUserId !== auth()->id()) {
            $currentTeam = null;
            $lastUserId = auth()->id();
        }
        
        if ($currentTeam === null) {
            $currentTeam = \Cache::remember(
                'currentTeam' . auth()->id(), 
                900,
                fn() => currentTeamRole()?->team
            );
        }

        return $currentTeam;
    }
}

/**
 * Optimized current team ID retrieval
 */
if (!function_exists('currentTeamId')) {
    function currentTeamId() 
    {
        return currentTeam()?->id;
    }
}

/**
 * Optimized current permissions retrieval
 */
if (!function_exists('currentPermissions')) {
    function currentPermissions() 
    {
        if (!auth()->user()) {
            return collect();
        }

        static $permissions = null;
        static $lastUserId = null;
        
        // Reset cache if user changed
        if ($lastUserId !== auth()->id()) {
            $permissions = null;
            $lastUserId = auth()->id();
        }
        
        if ($permissions === null) {
            $permissions = \Cache::rememberWithTags(
                ['permissions-v2'], 
                'currentPermissions' . auth()->id(), 
                900,
                function() {
                    $resolver = app(PermissionResolver::class);
                    return $resolver->getUserPermissionsOptimized(auth()->id());
                }
            );
        }

        return $permissions;
    }
}

/**
 * Check if current user is super admin with caching
 */
if (!function_exists('isAppSuperAdmin')) {
    function isAppSuperAdmin(): bool
    {
        if (!auth()->user()) {
            return false;
        }

        static $isSuperAdmin = null;
        static $lastUserId = null;
        
        // Reset cache if user changed
        if ($lastUserId !== auth()->id()) {
            $isSuperAdmin = null;
            $lastUserId = auth()->id();
        }
        
        if ($isSuperAdmin === null) {
            $isSuperAdmin = \Cache::remember(
                'isSuperAdmin' . auth()->id(),
                3600, // 1 hour
                fn() => auth()->user()->hasAccessToTeam(1, 'super-admin')
            );
        }

        return $isSuperAdmin;
    }
}

/**
 * Batch permission checking for multiple resources
 */
if (!function_exists('batchCheckPermissions')) {
    function batchCheckPermissions(array $permissions, PermissionTypeEnum $type = PermissionTypeEnum::READ): array
    {
        if (globalSecurityBypass()) {
            return array_fill_keys($permissions, true);
        }

        if (!auth()->user()) {
            return array_fill_keys($permissions, false);
        }

        $resolver = app(PermissionResolver::class);
        $results = [];
        
        foreach ($permissions as $permission) {
            $results[$permission] = $resolver->userHasPermission(
                auth()->id(),
                $permission,
                $type
            );
        }
        
        return $results;
    }
}

/**
 * Performance-optimized macro extensions
 */
\Kompo\Elements\BaseElement::macro('checkAuth', function ($id, $type = PermissionTypeEnum::READ, $specificTeamId = null, $returnNullInstead = false) {
    // Use static cache for repeated checks in same request
    static $permissionCache = [];
    $cacheKey = $id . '|' . $type->value . '|' . ($specificTeamId ?? 'null') . '|' . (auth()->id() ?? 'guest');
  
    if (!isset($permissionCache[$cacheKey])) {
        $permissionCache[$cacheKey] = checkAuthPermission($id, $type, $specificTeamId);
    }
    
    if ($permissionCache[$cacheKey]) {
        return $this;
    }

    if ($returnNullInstead) {
        return null;
    }

    return (new ($this::class))->class('hidden');
});

\Kompo\Elements\BaseElement::macro('checkAuthWrite', function ($id, $specificTeamId = null, $returnNullInstead = false) {
    return $this->checkAuth($id, PermissionTypeEnum::WRITE, $specificTeamId, $returnNullInstead);
});

Field::macro('readOnlyIfNotAuth', function ($id, $specificTeamId = null) {
    static $permissionCache = [];
    $cacheKey = $id . '|write|' . ($specificTeamId ?? 'null') . '|' . (auth()->id() ?? 'guest');
    
    if (!isset($permissionCache[$cacheKey])) {
        $permissionCache[$cacheKey] = checkAuthPermission($id, PermissionTypeEnum::WRITE, $specificTeamId);
    }
    
    if ($permissionCache[$cacheKey]) {
        return $this;
    }

    return $this->readOnly()->disabled()->class('!opacity-60');
});

\Kompo\Html::macro('hashIfNotAuth', function ($id, $specificTeamId = null, $minChars = 12) {
    static $permissionCache = [];
    $cacheKey = $id . '|read|' . ($specificTeamId ?? 'null') . '|' . (auth()->id() ?? 'guest');
    
    if (!isset($permissionCache[$cacheKey])) {
        $permissionCache[$cacheKey] = checkAuthPermission($id, PermissionTypeEnum::READ, $specificTeamId);
    }
    
    if ($permissionCache[$cacheKey]) {
        return $this;
    }

    $this->label = str_pad(preg_replace('/.*/', '*', $this->label), $minChars, '*');

    return $this;
});

Field::macro('hashIfNotAuth', function ($id, $specificTeamId = null, $minChars = 12) {
    static $permissionCache = [];
    $cacheKey = $id . '|read|' . ($specificTeamId ?? 'null') . '|' . (auth()->id() ?? 'guest');
    
    if (!isset($permissionCache[$cacheKey])) {
        $permissionCache[$cacheKey] = checkAuthPermission($id, PermissionTypeEnum::READ, $specificTeamId);
    }
    
    if ($permissionCache[$cacheKey]) {
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

/**
 * Optimized user info functions with caching
 */
if (!function_exists('authUser')) {
    function authUser()
    {
        static $user = null;
        static $lastCheck = null;
        
        // Only cache for current request to avoid stale data
        if ($lastCheck !== request()) {
            $user = auth()->user();
            $lastCheck = request();
        }
        
        return $user;
    }
}

if (!function_exists('authId')) {
    function authId()
    {
        return authUser()?->id;
    }
}

/**
 * Memory-efficient role checking
 */
if (!function_exists('isTeamOwner')) {
    function isTeamOwner(): bool
    {
        static $isOwner = null;
        static $lastUserId = null;
        
        if ($lastUserId !== auth()->id()) {
            $isOwner = null;
            $lastUserId = auth()->id();
        }
        
        if ($isOwner === null) {
            $isOwner = authUser()?->isTeamOwner() ?? false;
        }
        
        return $isOwner;
    }
}

if (!function_exists('isSuperAdmin')) {
    function isSuperAdmin(): bool
    {
        static $isSuperAdmin = null;
        static $lastUserId = null;
        
        if ($lastUserId !== auth()->id()) {
            $isSuperAdmin = null;
            $lastUserId = auth()->id();
        }
        
        if ($isSuperAdmin === null) {
            $isSuperAdmin = authUser()?->isSuperAdmin() ?? false;
        }
        
        return $isSuperAdmin;
    }
}

/**
 * Clear all auth-related static caches
 */
if (!function_exists('clearAuthStaticCache')) {
    function clearAuthStaticCache(): void
    {
        // This function helps with testing or when user switches
        // The static variables will be reset on next function calls
        \Cache::forget('currentTeamRole' . (auth()->id() ?? 0));
        \Cache::forget('currentTeam' . (auth()->id() ?? 0));
        \Cache::forget('isSuperAdmin' . (auth()->id() ?? 0));
    }
}

/**
 * Performance monitoring helpers
 */
if (!function_exists('startPermissionTimer')) {
    function startPermissionTimer(string $operation): void
    {
        if (config('kompo-auth.monitor-performance', false)) {
            app('permission-performance-monitor')->startTimer($operation);
        }
    }
}

if (!function_exists('endPermissionTimer')) {
    function endPermissionTimer(string $operation): array
    {
        if (config('kompo-auth.monitor-performance', false)) {
            return app('permission-performance-monitor')->endTimer($operation);
        }
        
        return [];
    }
}

/**
 * Enhanced validation rule functions
 */
if (!function_exists('registerRules')) {
    function registerRules(): array
    {
        static $rules = null;
        
        if ($rules === null) {
            $rules = array_merge(namesRules(), [
                'password' => passwordRules(),
                'terms' => ['required', 'accepted'],
            ]);
        }
        
        return $rules;
    }
}

if (!function_exists('namesRules')) {
    function namesRules(): array
    {
        static $rules = null;
        
        if ($rules === null) {
            $rules = config('kompo-auth.register_with_first_last_name') ? [
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
            ] : [
                'name' => ['required', 'string', 'max:255'],
            ];
        }
        
        return $rules;
    }
}

if (!function_exists('passwordRules')) {
    function passwordRules(): array
    {
        static $rules = null;
        
        if ($rules === null) {
            $passwordRules = new \Laravel\Fortify\Rules\Password();
            $rules = [
                'required', 
                'string', 
                $passwordRules->requireUppercase()->requireNumeric()->requireSpecialCharacter(), 
                'confirmed'
            ];
        }
        
        return $rules;
    }
}

if (!function_exists('baseEmailRules')) {
    function baseEmailRules(): array
    {
        static $rules = null;
        
        if ($rules === null) {
            $rules = ['required', 'string', 'email', 'max:255'];
        }
        
        return $rules;
    }
}

/**
 * Optimize memory by using more efficient caching for frequently called functions
 */
if (!function_exists('currentTeamRoleId')) {
    function currentTeamRoleId() 
    {
        return currentTeamRole()?->id;
    }
}

/**
 * Cleanup function for memory management
 */
if (!function_exists('cleanupAuthHelperCache')) {
    function cleanupAuthHelperCache(): void
    {
        // This function can be called to clear all static caches
        // Useful in long-running processes or testing
        clearAuthStaticCache();
        
        // Clear resolver cache if needed
        if (app()->bound(PermissionResolver::class)) {
            app(PermissionResolver::class)->clearAllCache();
        }
    }
}

/**
 * Execute a callback in bypass context to prevent infinite loops
 * This is useful when querying related models for security checks
 */
if (!function_exists('executeInBypassContext')) {
    function executeInBypassContext(callable $callback)
    {
        $wasInBypassContext = HasSecurity::isInBypassContext();
        
        if (!$wasInBypassContext) {
            HasSecurity::enterBypassContext();
        }
        
        try {
            return $callback();
        } finally {
            if (!$wasInBypassContext) {
                HasSecurity::exitBypassContext();
            }
        }
    }
}

/**
 * Check if we're currently in a bypass context
 */
if (!function_exists('isInBypassContext')) {
    function isInBypassContext(): bool
    {
        return HasSecurity::isInBypassContext();
    }
}

/**
 * Safely query related models for security checks
 * This prevents field protection from triggering during security bypass logic
 */
if (!function_exists('safeSecurityQuery')) {
    function safeSecurityQuery(callable $queryCallback)
    {
        return executeInBypassContext($queryCallback);
    }
}