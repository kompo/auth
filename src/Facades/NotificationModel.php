<?php

namespace Kompo\Auth\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @mixin \Kompo\Auth\Models\Monitoring\Notification
 */
class NotificationModel extends Facade
{
    use FacadeUtils;

    protected static function getFacadeAccessor()
    {
        return NOTIFICATION_MODEL_KEY;
    }
}