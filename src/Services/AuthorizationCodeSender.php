<?php

namespace Kompo\Auth\Services;

use Kompo\Auth\Models\AuthorizationCode;
use Kompo\Auth\Models\NotifiableMethodsEnum;

/**
 * Delivers an authorization code. A type picks its sender by key: register the class under
 * kompo-auth.authorization_code_senders and name that key in the type's 'sender' setting.
 * $context is whatever the caller of sendCode() passed along.
 */
abstract class AuthorizationCodeSender
{
    public function send(AuthorizationCode $authorizationCode, NotifiableMethodsEnum $via, string $destination, array $context = []): void
    {
        match ($via) {
            NotifiableMethodsEnum::SMS => $this->sms($authorizationCode, $destination, $context),
            NotifiableMethodsEnum::EMAIL => $this->email($authorizationCode, $destination, $context),
        };
    }

    abstract protected function email(AuthorizationCode $authorizationCode, string $email, array $context): void;

    abstract protected function sms(AuthorizationCode $authorizationCode, string $phone, array $context): void;
}
