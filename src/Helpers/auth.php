<?php

use Condoedge\Utils\Kompo\Elements\ValidatedInput;
use Kompo\Auth\Models\Plugins\HasSecurity;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Teams\PermissionResolver;
use Kompo\Auth\Teams\CacheKeyBuilder;
use Kompo\Date;
use Kompo\Elements\Field;
use Kompo\Place;
use Condoedge\Utils\Models\Model;
use Illuminate\Auth\Events\Registered;

if (!function_exists('systemUserId')) {
    /**
     * Get the system user ID from config
     */
    function systemUserId()
    {
        return config('kompo-auth.system_user_id', 1);
    }
}

if (!function_exists('systemUser')) {
    /**
     * Get the system user model instance
     */
    function systemUser()
    {
        return \Kompo\Auth\Facades\UserModel::find(systemUserId());
    }
}

if (!function_exists('fireRegisteredEvent')) {
    /**
     * Fire the Registered event for the current user
     * This is used to trigger any post-registration logic
     */
    function fireRegisteredEvent()
    {
        event(new Registered(auth()->user()));
    }
}

/**
 * Optimized permission checking with caching
 * 
 * @param Model|string $id the permission key or model instance in case of a model
 */
function checkAuthPermission($id, $type = PermissionTypeEnum::READ, $specificTeamId = null, $specificModel = null): bool
{
    if (globalSecurityBypass()) {
        return true;
    }

    if ($specificModel?->isSecurityBypassRequired()) {
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

if (!function_exists('permissionMustBeAuthorized')) {
    function permissionMustBeAuthorized($permissionKey)
    {
        if (globalSecurityBypass()) {
            return false;
        }

        if (!Permission::findByKey($permissionKey) && !config('kompo-auth.security.check-even-if-permission-does-not-exist', false)) {
            return false;
        }

        return true;
    }
}

if (!function_exists('routeIsByPassed')) {
    function routeIsByPassed()
    {
        $currentRoute = request()->route();

        if (!$currentRoute) {
            return false;
        }

        if ($currentRoute->uri() == '_kompo') {
            $referrerRoute = request()->headers->get('referer');
            $currentRoute = app('router')->getRoutes()->match(app('request')->create($referrerRoute));
        }

        if (!$currentRoute) {
            return false;
        }

        return in_array(
            'disable-automatic-security',
            $currentRoute->middleware()
        );
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

        if (!auth()->user()->currentTeamRole || !auth()->user()->currentTeamRole->roleRelation) {
            auth()->user()->switchToFirstTeamRole();
        }

        $cacheKey = CacheKeyBuilder::currentTeamRole(auth()->id());
        $tags = CacheKeyBuilder::getTagsForCacheType(CacheKeyBuilder::CURRENT_TEAM_ROLE);
        
        $currentTeamRole = \Cache::rememberWithTags(
            $tags,
            $cacheKey,
            900, // 15 minutes
            fn() => auth()->user()->currentTeamRole
        );

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

        $cacheKey = CacheKeyBuilder::currentTeam(auth()->id());
        $tags = CacheKeyBuilder::getTagsForCacheType(CacheKeyBuilder::CURRENT_TEAM);
        
        $currentTeam = \Cache::rememberWithTags(
            $tags,
            $cacheKey,
            900,
            fn() => currentTeamRole()?->team
        );

        if (!$currentTeam) {
            auth()->user()->resetToValidTeamRole();
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
 * Check if current user is super admin with caching
 */
if (!function_exists('isAppSuperAdmin')) {
    function isAppSuperAdmin(): bool
    {
        if (!auth()->user()) {
            return false;
        }

        $cacheKey = CacheKeyBuilder::isSuperAdmin(auth()->id());
        $tags = CacheKeyBuilder::getTagsForCacheType(CacheKeyBuilder::IS_SUPER_ADMIN);
        
        $isSuperAdmin = \Cache::rememberWithTags(
            $tags,
            $cacheKey,
            3600, // 1 hour
            fn() => isSuperAdmin() || auth()->user()->isSuperAdmin()
        );

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
\Kompo\Elements\BaseElement::macro('checkAuth', function ($id, $type = PermissionTypeEnum::READ, $specificTeamId = null, $returnNullInstead = false, $specificModel = null) {
    // Use static cache for repeated checks in same request
    static $permissionCache = [];
    $cacheKey = $id . '|' . $type->value . '|' . ($specificTeamId ?? 'null') . '|' . (auth()->id() ?? 'guest') . '|' . ($specificModel?->getKey() ?? 'null');

    if (!isset($permissionCache[$cacheKey])) {
        $permissionCache[$cacheKey] = checkAuthPermission($id, $type, $specificTeamId, $specificModel);
    }

    if ($permissionCache[$cacheKey]) {
        return $this;
    }

    if ($returnNullInstead) {
        return null;
    }

    return (new ($this::class))->class('hidden');
});

\Kompo\Elements\BaseElement::macro('checkAuthWrite', function ($id, $specificTeamId = null, $returnNullInstead = false, $specificModel = null) {
    return $this->checkAuth($id, PermissionTypeEnum::WRITE, $specificTeamId, $returnNullInstead, $specificModel);
});

Field::macro('readOnlyIfNotAuth', function ($id, $specificTeamId = null, $specificModel = null) {
    static $permissionCache = [];
    $cacheKey = $id . '|write|' . ($specificTeamId ?? 'null') . '|' . (auth()->id() ?? 'guest') . '|' . ($specificModel?->getKey() ?? 'null');

    if (!isset($permissionCache[$cacheKey])) {
        $permissionCache[$cacheKey] = checkAuthPermission($id, PermissionTypeEnum::WRITE, $specificTeamId, $specificModel);
    }

    if ($permissionCache[$cacheKey]) {
        return $this;
    }

    return $this->readOnly()->disabled()->class('!opacity-60');
});

Field::macro('hashIfNotAuth', function ($id, $specificTeamId = null, $minChars = 12, $specificModel = null) {
    static $permissionCache = [];
    $cacheKey = $id . '|read|' . ($specificTeamId ?? 'null') . '|' . (auth()->id() ?? 'guest');

    if (!isset($permissionCache[$cacheKey])) {
        $permissionCache[$cacheKey] = checkAuthPermission($id, PermissionTypeEnum::READ, $specificTeamId, $specificModel);
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

Field::macro('hashAndReadOnlyIfNotAuth', function ($id, $specificTeamId = null, $minChars = 12, $specificModel = null) {
    return $this->hashIfNotAuth("{$id}.sensibleColumns", $specificTeamId, minChars: $minChars, specificModel: $specificModel)->readOnlyIfNotAuth($id, $specificTeamId, specificModel: $specificModel)->readOnlyIfNotAuth("{$id}.sensibleColumns", $specificTeamId, specificModel: $specificModel);
});

\Kompo\Html::macro('hashIfNotAuthHtml', function ($id, $specificTeamId = null, $minChars = 12, $specificModel = null) {
    static $permissionCache = [];
    $cacheKey = $id . '|read|' . ($specificTeamId ?? 'null') . '|' . (auth()->id() ?? 'guest') . '|' . ($specificModel?->getKey() ?? 'null');

    if (!isset($permissionCache[$cacheKey])) {
        $permissionCache[$cacheKey] = checkAuthPermission($id, PermissionTypeEnum::READ, $specificTeamId, $specificModel);
    }

    if ($permissionCache[$cacheKey]) {
        return $this;
    }

    $this->label = str_pad(preg_replace('/.*/', '*', $this->label), $minChars, '*');

    return $this;
});

/**
 * Optimized user info functions with caching
 */
if (!function_exists('authUser')) {
    function authUser()
    {
        return auth()->user();
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
        return authUser()?->isTeamOwner() ?? false;
    }
}

if (!function_exists('isSuperAdmin')) {
    function isSuperAdmin(): bool
    {
        return authUser()?->isSuperAdmin() ?? false;
    }
}

/**
 * Clear all auth-related static caches
 */
if (!function_exists('clearAuthStaticCache')) {
    function clearAuthStaticCache(): void
    {
        // This function helps with testing or when user switches
        // Clear current user context cache types using tags
        \Cache::flushTags([CacheKeyBuilder::CURRENT_TEAM_ROLE]);
        \Cache::flushTags([CacheKeyBuilder::CURRENT_TEAM]);
        \Cache::flushTags([CacheKeyBuilder::IS_SUPER_ADMIN]);
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
        if (config('kompo-auth.register-rules')) {
            return config('kompo-auth.register-rules');
        }

        return array_merge(namesRules(), [
                'password' => passwordRules(),
                'terms' => ['required', 'accepted'],
        ]);
    }
}

if (!function_exists('namesRules')) {
    function namesRules(): array
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
    function passwordRules(): array
    {
        $passwordRules = new \Laravel\Fortify\Rules\Password();
        $rules = [
            'required',
            'string',
            $passwordRules->requireUppercase()->requireNumeric()->requireSpecialCharacter(),
            'confirmed'
        ];

        return $rules;
    }
}

if (!function_exists('baseEmailRules')) {
    function baseEmailRules(): array
    {
        return ['required', 'string', 'email', 'max:255'];
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

/**
 * Batch load field protection permissions for a collection
 * Use this before accessing sensitive fields on multiple models to prevent N+1 queries
 *
 * @param \Illuminate\Support\Collection|array $models
 * @param int|null $userId
 * @return void
 */
if (!function_exists('batchLoadFieldProtection')) {
    function batchLoadFieldProtection($models)
    {
        return HasSecurity::batchLoadFieldProtectionPermissions($models);
    }
}
