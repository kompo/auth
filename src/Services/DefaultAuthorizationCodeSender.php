<?php

namespace Kompo\Auth\Services;

use Illuminate\Support\Facades\Mail;
use Kompo\Auth\Mail\AuthorizationCodeMail;
use Kompo\Auth\Models\AuthorizationCode;

class DefaultAuthorizationCodeSender extends AuthorizationCodeSender
{
    protected function email(AuthorizationCode $authorizationCode, string $email, array $context): void
    {
        Mail::to($email)->send(new AuthorizationCodeMail($authorizationCode));
    }

    protected function sms(AuthorizationCode $authorizationCode, string $phone, array $context): void
    {
        $content = __('auth-your-coolecto-authorization-code-is', ['code' => $authorizationCode->code]);
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');

        $vonage = app(\Vonage\Client::class);

        $vonage->sms()->send(
            new \Vonage\SMS\Message\SMS(
                $phone,
                config('services.vonage.sms_from'),
                $content
            )
        );
    }
}
