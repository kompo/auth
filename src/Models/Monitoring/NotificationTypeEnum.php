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
                $this->getButtons($notification),
                $notification->has_reminder_button,
            ),
        };
    }

    /**
     * Resolve the buttons to render for this notification.
     *
     * Returns an array of Kompo Elements. The card layer wraps them in a Flex.
     * Single-button handlers keep their `getButton()` shape; multi-button handlers
     * implement `MultiNotificationButtonHandler::getButtons()`.
     *
     * @return array<\Kompo\Elements\Element>
     */
    protected function getButtons($notification): array
    {
        $handlerClass = $notification->custom_button_handler ?? config('kompo-auth.notifications.default_notification_button_handler', DefaultNotificationButtonHandler::class);
        $handler = $handlerClass ? new $handlerClass($notification) : null;

        if ($handler instanceof \Condoedge\Communications\Services\CommunicationHandlers\Contracts\MultiNotificationButtonHandler) {
            return array_filter($handler->getButtons());
        }

        if ($handler) {
            $button = $handler->getButton();
            return $button ? [$button] : [];
        }

        if ($notification->custom_button_text && $notification->custom_button_href) {
            return [
                _Link2Button($notification->custom_button_text)->button()
                    ->href($notification->custom_button_href),
            ];
        }

        return [];
    }
}