<?php

namespace Kompo\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Notifications\Notification;
use Kompo\Auth\Mail\AuthorizationCodeMail;
use Kompo\Auth\Models\AuthorizationCode;
use Kompo\Auth\Models\NotifiableMethodsEnum;

class AuthorizationCodeNotification extends Notification
{
    use Queueable;

    protected NotifiableMethodsEnum $notifiableMethod;
    protected AuthorizationCode $authorizationCode;

    /**
     * Create a new notification instance.
     */
    public function __construct(AuthorizationCode $authorizationCode, NotifiableMethodsEnum $notifiableMethod)
    {
        $this->authorizationCode = $authorizationCode;
        $this->notifiableMethod = $notifiableMethod;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [$this->notifiableMethod->via()];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): Mailable
    {
        return (new AuthorizationCodeMail($this->authorizationCode))->to($notifiable->email);
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        $content = 'Your authorization code is ' . $this->authorizationCode->code . '.';

        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');

        return (new VonageMessage())
            ->clientReference($notifiable->id)
            ->content($content);
    }
}
