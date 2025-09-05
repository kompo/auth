<?php

namespace Kompo\Auth\Models\Monitoring;

enum NotificationTypeEnum: int
{
    use \Condoedge\Utils\Models\Traits\EnumKompo;

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
                $this->getButton($notification),
                $notification->has_reminder_button,
            ),
        };
    }

    protected function getButton($notification)
    {
        $handlerClass = $notification->custom_button_handler;
        $handler = $handlerClass ? new $handlerClass($notification) : null;

        $button = null;

        if ($handler) {
            $button = $handler->getButton();
        } else if ($notification->custom_button_text && $notification->custom_button_href) {
            $button = _Link2Button($notification->custom_button_text)->button()->href($notification->custom_button_href);
        }

        return $button;
    }
}