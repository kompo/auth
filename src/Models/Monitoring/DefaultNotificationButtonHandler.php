<?php

namespace Kompo\Auth\Models\Monitoring;

class DefaultNotificationButtonHandler
{
    protected $notification;

    public function __construct($notification)
    {
        $this->notification = $notification;
    }

    public function getButton()
    {
        if (!$this->notification->custom_button_href) {
            return null;
        }

        return _Link2Button($this->notification->custom_button_text)->button()
            ->get('notifications.button-action', ['id' => $this->notification->id]);
    }
}