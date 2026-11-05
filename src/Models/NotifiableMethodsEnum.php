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
            // A host can have `phone` as a relation: only a string is a number.
            self::SMS => is_string($notifiable->phone ?? null) ? $notifiable->phone : ($notifiable->phone_number ?? null),
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
