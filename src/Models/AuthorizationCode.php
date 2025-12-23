<?php

namespace Kompo\Auth\Models;

use Condoedge\Utils\Models\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Kompo\Auth\Models\Traits\BelongsToUserTrait;
use Kompo\Auth\Notifications\AuthorizationCodeNotification;

class AuthorizationCode extends Model
{
    use BelongsToUserTrait;
    use HasFactory;

    public const EXPIRATION_TIME = 5;

    /* SCOPES */
    public function scopeUnused($query)
    {
        return $query->whereNull('used_at');
    }

    public function scopeNotExpired($query)
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeValid($query)
    {
        return $query->unused()->notExpired();
    }

    public function scopeFindByUser($query, $userId, $type = 'generic')
    {
        return $query->where('user_id', $userId)->where('type', $type);
    }

    public function scopeFindByIpAddress($query, $ipAddress, $type = 'generic')
    {
        return $query->where('ip_address', $ipAddress)->where('type', $type);
    }

    public function scopeFindByEmail($query, $email, $type = 'generic')
    {
        return $query->where('email', $email)->where('type', $type);
    }

    public function scopeFindByPhone($query, $phone, $type = 'generic')
    {
        return $query->where('phone', $phone)->where('type', $type);
    }

    /* ACTIONS */
    public static function createNew($userId = null, $code = null, $type = 'generic', $email = null, $phone = null)
    {
        static::deleteExisting($userId, $type, $email, $phone);

        $code = $code ?? sprintf("%06d", mt_rand(1, 999999));

        $authorizationCode = new self();
        $authorizationCode->ip_address = request()->ip();
        $authorizationCode->user_id = $userId;
        $authorizationCode->email = $email;
        $authorizationCode->phone = $phone;
        $authorizationCode->code = $code;
        $authorizationCode->type = $type;
        $authorizationCode->expires_at = now()->addMinutes(self::EXPIRATION_TIME);
        $authorizationCode->save();

        return $authorizationCode;
    }

    protected static function deleteExisting($userId, $type, $email = null, $phone = null)
    {
        return self::findByIdentifier($userId, $email, $phone, $type)
            ->delete();
    }

    public static function verify($userId = null, $code, $type = 'generic', $email = null, $phone = null)
    {
        $authCode = self::valid()
            ->findByIdentifier($userId, $email, $phone, $type)
            ->where('code', $code)
            ->latest()
            ->first();

        if (!$authCode) {
            return false;
        }

        $authCode->used_at = now();
        $authCode->save();

        return true;
    }

    public function scopeFindByIdentifier($query, $userId, $email, $phone, $type)
    {
        return match(true) {
            (bool) $userId => $query->findByUser($userId, $type),
            (bool) $email => $query->findByEmail($email, $type),
            (bool) $phone => $query->findByPhone($phone, $type),
            default => $query->findByIpAddress(request()->ip(), $type),
        };
    }

    public function send($via = null)
    {
        $via = $via ?? ($this->email ? NotifiableMethodsEnum::EMAIL : NotifiableMethodsEnum::SMS);

        if ($this->user) {
            $this->user->notify(new AuthorizationCodeNotification($this, $via));
            return;
        }

        $this->sendDirect($via);
    }

    protected function sendDirect($via)
    {
        if ($via === NotifiableMethodsEnum::EMAIL && $this->email) {
            \Illuminate\Support\Facades\Mail::to($this->email)
                ->send(new \Kompo\Auth\Mail\AuthorizationCodeMail($this));

            return;
        }

        if ($via === NotifiableMethodsEnum::SMS && $this->phone) {
            $this->sendSmsDirectly();

            return;
        }
    }

    protected function sendSmsDirectly()
    {
        $content = __('translate.auth.your-authorization-code-is', ['code' => $this->code]);
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');

        $vonage = app(\Vonage\Client::class);

        $vonage->sms()->send(
            new \Vonage\SMS\Message\SMS(
                $this->phone,
                config('services.vonage.sms_from'),
                $content
            )
        );
    }
}
