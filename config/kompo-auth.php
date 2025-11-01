<?php

return [
    'breadcrumbs' => [
        'clickeable-action' => true,
    ],

    'security' => [
        'bypass-security' => env('BYPASS_SECURITY', false),
        'default-read-security-restrictions' => true,
        'default-delete-security-restrictions' => true,
        'default-save-security-restrictions' => true,
        'default-restrict-by-team' => true,

        'dont-check-if-not-logged-in' => false,
        'dont-check-if-impersonating' => false,

        'check-even-if-permission-does-not-exist' => false,

        'lazy-protected-fields' => false, // Better performance but the attributes internal array will contain unsafe fields

        'default-validate-owned-as-well' => false, // Enforce owner validation by default in addition to permissions
    ],

    'notifications' => [
        'default_notification_button_handler' => \Kompo\Auth\Models\Monitoring\DefaultNotificationButtonHandler::class,
    ],

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

    USER_MODEL_KEY . '-namespace' => getAppClass(App\Models\User::class, \Kompo\Auth\Models\User::class),
];