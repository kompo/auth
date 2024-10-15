<?php

namespace Kompo\Auth\Models\Monitoring\Layout;

use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\VonageMessage;

class DefaultLayoutSmsCommunicable extends Mailable
{
    public $communication;

    public function __construct($communication)
    {
        $this->communication = $communication;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['vonage'];
    }

    /**
     * Get the Vonage / SMS representation of the notification.
     */
    public function toVonage(object $notifiable): VonageMessage
    {
        return (new VonageMessage)
            ->content($this->communication->content);
    }
}