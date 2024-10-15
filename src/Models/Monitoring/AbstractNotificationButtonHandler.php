<?php

namespace Kompo\Auth\Models\Monitoring;

use Kompo\Elements\Element;

abstract class AbstractNotificationButtonHandler
{
    protected $notification;

    public function __construct($notification)
    {
        $this->notification = $notification;
    }

    abstract public function getButton(): Element;
}