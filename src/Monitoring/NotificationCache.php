<?php

namespace Kompo\Auth\Monitoring;

use Illuminate\Support\Facades\Cache;

class NotificationCache
{
    public function forgetDiscussionsNotificationsForUser($userId): void
    {
        if (!$userId) {
            return;
        }

        Cache::forget('discussionsNotifications' . $userId);
    }
}
