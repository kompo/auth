<?php

namespace Kompo\Auth\Models;

enum NotifiableMethodsEnum: string
{
    use \Condoedge\Utils\Models\Traits\EnumKompo;

    case SMS = 'sms';
    case EMAIL = 'email';

    public function label()
    {
        return match ($this) {
            self::SMS => __('crm.sms'),
            self::EMAIL => __('crm.email'),
        };
    }

    public function via()
    {
        return match ($this) {
            self::SMS => 'vonage',
            self::EMAIL => 'mail',
        };
    }

    public function destination($notifiable)
    {
        return match ($this) {
            self::SMS => $notifiable->phone ?? $notifiable->phone_number,
            self::EMAIL => $notifiable->email,
        };
    }

    public function hiddenDestination($notifiable)
    {
        return match ($this) {
            self::SMS => hidePhone($this->destination($notifiable)),
            self::EMAIL => hideEmail($this->destination($notifiable)),
        };
    }
}
