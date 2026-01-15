<?php

namespace Kompo\Auth\Services;

use Kompo\Auth\Models\AuthorizationCode;
use Kompo\Auth\Models\NotifiableMethodsEnum;

class AuthorizationCodeService
{
    protected $user;
    protected $email;
    protected $phone;

    public function getAvailableVias()
    {
        if ($this->user) {
            return collect(NotifiableMethodsEnum::cases())
                ->filter(function ($method) {
                    return $method->destination($this->user);
                })->toArray();
        }

        $vias = [];

        if ($this->email) {
            $vias[] = NotifiableMethodsEnum::EMAIL;
        }

        if ($this->phone) {
            $vias[] = NotifiableMethodsEnum::SMS;
        }

        return $vias;
    }


    /**
     * We should not use logic from AuthorizationCode, but at some point we'll refactor and at least
     * here we ensure the logic comes from a single place, that can have different implementations (also hidding the specific implementation since it's an extendable module).
     */
    public function sendCode($type = 'generic', $via = NotifiableMethodsEnum::SMS)
    {
        $authorizationCode = AuthorizationCode::createNew($this->user?->id, null, $type, $this->email, $this->phone);
        $authorizationCode->send($via);

        return $authorizationCode;
    }

    public function verify($type = 'generic', $code)
    {
        return AuthorizationCode::verify(
            $this->user?->id,
            $code,
            $type,
            $this->email,
            $this->phone
        );
    }



    /* SETTERS */
    public function setUser($user)
    {
        $this->user = $user;

        return $this;
    }

    public function setEmail($email)
    {
        $this->email = $email;

        return $this;
    }

    public function setPhone($phone)
    {
        $this->phone = $phone;

        return $this;
    }
}
