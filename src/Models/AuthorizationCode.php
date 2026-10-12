<?php

namespace Kompo\Auth\Models;

use Condoedge\Utils\Models\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\RateLimiter;
use Kompo\Auth\Models\Traits\BelongsToUserTrait;
use Kompo\Auth\Services\AuthorizationCodeSender;

class AuthorizationCode extends Model
{
    use BelongsToUserTrait;
    use HasFactory;

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

    /* CALCULATED FIELDS */
    /** The type's own setting, or the one every type shares: kompo-auth.authorization_code_*. */
    public static function setting($key, $type = 'generic')
    {
        return config("kompo-auth.authorization_code_types.{$type}.{$key}") ?? config("kompo-auth.authorization_code_{$key}");
    }

    public static function tooManySends($userId = null, $type = 'generic', $email = null, $phone = null)
    {
        return RateLimiter::tooManyAttempts(self::limiterKey('sends', $userId, $type, $email, $phone), self::setting('max_sends', $type));
    }

    protected static function limiterKey($what, $userId, $type, $email, $phone)
    {
        $identifier = $userId ? 'user-' . $userId : ($email ?: $phone ?: request()->ip());

        return 'authorization-code.' . $what . '.' . $type . '.' . sha1(strtolower((string) $identifier));
    }

    protected function sender(): AuthorizationCodeSender
    {
        return app(config('kompo-auth.authorization_code_senders.' . self::setting('sender', $this->type)));
    }

    /* ACTIONS */
    public static function createNew($userId = null, $code = null, $type = 'generic', $email = null, $phone = null)
    {
        if (self::tooManySends($userId, $type, $email, $phone)) {
            abort(429, __('error-too-many-authorization-codes'));
        }

        $minutes = self::setting('expiration', $type);

        RateLimiter::hit(self::limiterKey('sends', $userId, $type, $email, $phone), $minutes * 60);
        RateLimiter::clear(self::limiterKey('tries', $userId, $type, $email, $phone));

        static::deleteExisting($userId, $type, $email, $phone);

        $code = $code ?? sprintf("%06d", mt_rand(1, 999999));

        $authorizationCode = new self();
        $authorizationCode->ip_address = request()->ip();
        $authorizationCode->user_id = $userId;
        $authorizationCode->email = $email;
        $authorizationCode->phone = $phone;
        $authorizationCode->code = $code;
        $authorizationCode->type = $type;
        $authorizationCode->expires_at = now()->addMinutes($minutes);
        $authorizationCode->save();

        return $authorizationCode;
    }

    protected static function deleteExisting($userId, $type, $email = null, $phone = null)
    {
        return self::findByIdentifier($userId, $email, $phone, $type)
            ->delete();
    }

    /** A right code works once; too many wrong ones void it. */
    public static function verify($userId = null, $code, $type = 'generic', $email = null, $phone = null)
    {
        $tries = self::limiterKey('tries', $userId, $type, $email, $phone);

        if (RateLimiter::tooManyAttempts($tries, self::setting('max_tries', $type))) {
            static::deleteExisting($userId, $type, $email, $phone);

            return false;
        }

        $authCode = self::valid()
            ->findByIdentifier($userId, $email, $phone, $type)
            ->where('code', $code)
            ->latest()
            ->first();

        if (!$authCode) {
            RateLimiter::hit($tries, self::setting('expiration', $type) * 60);

            return false;
        }

        $authCode->used_at = now();
        $authCode->save();

        RateLimiter::clear($tries);

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

    public function send($via = null, array $context = [])
    {
        $via = $via ?? ($this->email ? NotifiableMethodsEnum::EMAIL : NotifiableMethodsEnum::SMS);

        $destination = match ($via) {
            NotifiableMethodsEnum::SMS => $this->phone,
            NotifiableMethodsEnum::EMAIL => $this->email,
        } ?? ($this->user ? $via->destination($this->user) : null);

        if ($destination) {
            $this->sender()->send($this, $via, $destination, $context);
        }
    }
}
