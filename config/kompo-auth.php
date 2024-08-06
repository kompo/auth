<?php

return [
    'register_with_first_last_name' => true,

    'img_form_layout_default_class' => '',

    'multiple_roles_per_team' => false,
    
    'team_hierarchy_roles' => false,

    'sso-services' => ['google', 'azure'], 

    'team-model-namespace' => getAppClass(App\Models\Teams\Team::class, Kompo\Auth\Models\Teams\Team::class),

    'notification-model-namespace' => Kompo\Auth\Models\Monitoring\Notification::class,
    'note-model-namespace' => Kompo\Auth\Models\Notes\Note::class,
];