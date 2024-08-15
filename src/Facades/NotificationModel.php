<?php

namespace Kompo\Auth\Facades;

use Kompo\Komponents\Form\KompoModelFacade;

/**
 * @mixin \Kompo\Auth\Models\Monitoring\Notification
 */
class NotificationModel extends KompoModelFacade
{
    protected static function getModelBindKey()
    {
        return NOTIFICATION_MODEL_KEY;
    }
}