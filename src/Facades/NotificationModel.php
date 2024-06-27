<?php

namespace Kompo\Auth\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Kompo\Auth\Models\Monitoring\Notification
 */
class NotificationModel extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'notification-model';
    }
}