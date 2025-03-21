<?php

use Kompo\Auth\GlobalConfig\DbGlobalConfigService;
use Kompo\Auth\GlobalConfig\FileGlobalConfigService;

return [
    'global_config_service' => [
        'driver' => env('GLOBAL_CONFIG_SERVICE_DRIVER', 'file'),
        'drivers' => [
            'file' => [
                'class' => FileGlobalConfigService::class,
            ],
            'db' => [
                'class' => DbGlobalConfigService::class,
            ],
        ],
    ]
];