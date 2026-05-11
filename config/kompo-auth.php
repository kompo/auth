<?php

use Kompo\Auth\Models\NotifiableMethodsEnum;

return [
    'force-to-reset-password-after-x-days' => env('FORCE_REQUEST_PASSWORD_AFTER_X_DAYS', null), //If null we don't set it and they never need it

    'breadcrumbs' => [
        'clickeable-action' => true,
    ],

    'root-security' => true,

    'load-migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Per-concern config tree. Every behavior knob lives here; the package
    | reads it via `kompoAuthSecurityConfig('{concern}.{key}')`.
    |
    | Behavior values where shown:
    |   'auto'   — applied by default; caller can opt out via a Builder macro.
    |   'opt-in' — off by default; caller opts in via a Builder macro.
    |   'off'    — never applied; the underlying scope/macro is a no-op.
    |
    */
    'security' => [

        /*
         * Read scope. Defaults preserve the pre-refactor behavior — the user
         * sees every team they have permission in. To restrict to current team
         * by default, set `multi_team` to `'opt-in'` and call
         * `->withMultiTeamAccess()` on lists that legitimately span teams.
         */
        'read' => [
            'enabled'       => true,
            'current_team'  => 'auto',   // narrows to currentTeamId; ->withoutCurrentTeamScope() drops it
            'multi_team'    => 'auto',   // exposes every permitted team; ->withCurrentTeamOnly() narrows
            'owned_records' => 'auto',   // OR-clause for HasOwnedRecords on team-scoped reads
        ],

        'write' => [
            'enabled'      => true,
            'current_team' => 'auto',
            'multi_team'   => 'auto',
        ],

        'delete' => [
            'enabled'      => true,
            'current_team' => 'auto',
            'multi_team'   => 'auto',
        ],

        'fields' => [
            'enabled'               => true,
            'eager_load_protection' => true,    // toggles InterceptsRelations
            'gate_inserts'          => false,   // dirty-write check on fresh inserts too
        ],

        'bypass' => [
            'global'          => env('BYPASS_SECURITY', false),  // process-wide kill switch
            'super_admin'     => true,
            'console'         => true,
            'unauthenticated' => true,          // Check this. Because it's a fix, it doesn't mean in each place we use this is before auth process happened.
            'route_opt_out'   => true,          // honors `disable-automatic-security` middleware
        ],

        'permission' => [
            // When false, `permissionMustBeAuthorized` returns false for unknown keys (skips the
            // gate). When true, treats unknown keys as still authorized (stricter).
            'check_even_if_missing' => false,
        ],

        'owned_records' => [
            // Even owners must hold the permission — disables the user_id and
            // HasOwnedRecords bypass paths in addition to permission checks.
            'validate_as_well' => false,
        ],

        // Warn once per class when a model with a `team_id` column lacks
        // `ScopedToTeam`. The package falls back to `whereIn('team_id', ...)`.
        'warn_on_missing_team_contract' => true,

        // Warn once per class when a model with a `user_id` column lacks
        // `HasOwnedRecords`. No auto-detect — the warning only.
        'warn_on_missing_owned_records_contract' => true,
    ],

    'notifications' => [
        'default_notification_button_handler' => \Kompo\Auth\Models\Monitoring\DefaultNotificationButtonHandler::class,
    ],

    'default_authorization_via' => NotifiableMethodsEnum::SMS,

    // Performance monitoring
    'monitor-performance' => env('KOMPO_AUTH_MONITOR_PERFORMANCE', false),
    'performance-thresholds' => [
        'execution_time_ms' => 1000,
        'memory_usage_mb' => 50,
        'max_permission_queries' => 20,
        'slow_query_ms' => 100,
    ],

    // Cache configuration
    'cache' => [
        'ttl' => 900, // 15 minutes
        'hierarchy_ttl' => 3600,
        'role_switcher_ttl' => 900,
        'super_admin_ttl' => 3600,
        'permission_lookup_ttl' => 60,
        'permission_definition_ttl' => 3600,
        'role_list_ttl' => 3600,
        'tags_enabled' => true,
        'warm_critical_users' => true,
        'max_cache_size_mb' => 100,
    ],

    // Team hierarchy optimization
    'team_hierarchy' => [
        'max_depth' => 10,
        'cache_warming' => true,
        'batch_size' => 50,
    ],

    'team_role_switcher' => [
        'committees_enabled' => false,
    ],

    'superadmin-emails' => [
        ...explode(',', env('SUPERADMIN_EMAILS', '')),
    ],

    'register_with_first_last_name' => true,
    'img_form_layout_default_class' => '',
    'multiple_roles_per_team' => false,
    'team_hierarchy_roles' => false,
    'sso-services' => ['google', 'azure'], 

    // Model namespaces
    'team-model-namespace' => getAppClass(App\Models\Teams\Team::class, Kompo\Auth\Models\Teams\Team::class),
    'role-model-namespace' => getAppClass(App\Models\Teams\Roles\Role::class, Kompo\Auth\Models\Teams\Roles\Role::class),
    'notification-model-namespace' => Kompo\Auth\Models\Monitoring\Notification::class,

    // Component namespaces
    'assign-role-modal-namespace' => getAppClass(App\Kompo\Teams\Roles\AssignRoleModal::class, Kompo\Auth\Teams\Roles\AssignRoleModal::class),
    'role-form-namespace' => getAppClass(App\Kompo\Teams\Roles\RoleForm::class, Kompo\Auth\Teams\Roles\RoleForm::class),

    'default-added-by-modified-by' => 1,
    'profile-enum' => getAppClass(App\Models\Teams\ProfileEnum::class, Kompo\Auth\Models\Teams\ProfileEnum::class),

    'user-model-namespace' => getAppClass(App\Models\User::class, \Kompo\Auth\Models\User::class),
];
