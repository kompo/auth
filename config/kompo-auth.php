<?php

return [
    'security' => [
        'bypass-security' => env('BYPASS_SECURITY', false),

        'default-read-security-restrictions' => true,
        'default-delete-security-restrictions' => true,
        'default-save-security-restrictions' => true,
        'default-restrict-by-team' => true,
    ],

    'superadmin-emails' => [
        ...explode(',', env('SUPERADMIN_EMAILS', '')),
    ],

    'register_with_first_last_name' => true,

    'img_form_layout_default_class' => '',

    'multiple_roles_per_team' => false,
    
    'team_hierarchy_roles' => false,

    'sso-services' => ['google', 'azure'], 

    'team-model-namespace' => getAppClass(App\Models\Teams\Team::class, Kompo\Auth\Models\Teams\Team::class),
    'role-model-namespace' => getAppClass(App\Models\Teams\Roles\Role::class, Kompo\Auth\Models\Teams\Roles\Role::class),

    'notification-model-namespace' => Kompo\Auth\Models\Monitoring\Notification::class,

    'assign-role-modal-namespace' => getAppClass(App\Kompo\Teams\Roles\AssignRoleModal::class, Kompo\Auth\Teams\Roles\AssignRoleModal::class),
    'role-form-namespace' => getAppClass(App\Kompo\Teams\Roles\RoleForm::class, Kompo\Auth\Teams\Roles\RoleForm::class),

    'check-if-user-has-permission' => true,

    'default-added-by-modified-by' => 1,

    'profile-enum' => getAppClass(App\Models\Teams\ProfileEnum::class, Kompo\Auth\Models\Teams\ProfileEnum::class),

    USER_MODEL_KEY . '-namespace' => getAppClass(App\ModelsModels\User::class, \Kompo\Auth\Models\User::class),
];