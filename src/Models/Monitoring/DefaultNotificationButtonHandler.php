<?php

namespace Kompo\Auth\Models\Monitoring;

use Illuminate\Support\Facades\URL;

class DefaultNotificationButtonHandler
{
    protected $notification;

    public function __construct($notification)
    {
        $this->notification = $notification;
    }

    public function getButton()
    {
        return _Link2Button($this->notification->custom_button_text)->button()
            ->post(URL::signedRoute('notifications.mark-seen', ['id' => $this->notification->id]))
            ->redirect($this->notification->custom_button_href);
    }
}