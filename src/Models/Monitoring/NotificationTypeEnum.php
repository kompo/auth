<?php

namespace Kompo\Auth\Models\Monitoring;

enum NotificationTypeEnum: int
{
    use \Kompo\Auth\Models\Traits\EnumKompo;

    case CUSTOM = 1;

    public function label()
    {
        return match ($this) {
            self::CUSTOM => 'notifications-custom-notification',
        };
    }

    public function getContent($notification)
    {
        return match ($this) {
            self::CUSTOM => $notification->genericNotificationCard(
                    $notification->custom_message, 
                    !$notification->custom_button_text ? null : _Link($notification->custom_button_text)->button()->href($notification->custom_button_href),
                    $notification->has_reminder_button,
            ),
        };
    }
}