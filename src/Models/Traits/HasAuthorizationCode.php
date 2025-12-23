<?php

namespace Kompo\Auth\Models\Traits;

use Kompo\Auth\Models\AuthorizationCode;
use Kompo\Auth\Models\NotifiableMethodsEnum;

trait HasAuthorizationCode
{
    public function notifyAuthorizationCode($type = 'generic', $via = NotifiableMethodsEnum::SMS)
    {
        $code = sprintf("%06d", mt_rand(1, 999999));

        $authorizationCode = AuthorizationCode::createNew($this->id, $code, $type);
        $authorizationCode->send($via);
    }

    public function verifyAuthorizationCode($code, $type = 'generic')
    {
        $verification = AuthorizationCode::verify($this->id, $code, $type);

        if (!$verification) {
            abort(403, __('error.invalid-authorization-code'));
        }
    }
}