<?php

namespace Kompo\Auth\Models\Teams;

use Illuminate\Support\Carbon;
use Condoedge\Utils\Models\ModelBase;
use Illuminate\Support\Facades\URL;
use Kompo\Auth\Facades\UserModel;

class EmailRequest extends ModelBase
{
    /* CALCULATED FIELDS */
    public function hasVerifiedEmail()
    {
        return ! is_null($this->email_verified_at);
    }

    public function getEmailForVerification()
    {
        return $this->email;
    }

    public function getRegisterRoute()
    {
        return URL::temporarySignedRoute(
            'register', 
            Carbon::now()->addMinutes(30),
            [
                'email_request_id' => $this->id,
            ]
        );
    }

    public function getRelatedUser()
    {
        return UserModel::where('email', $this->email)->first();
    }

    /* ACTIONS */
    public static function getOrCreateWithRegisterUrl($email)
    {
        $emailRequest = static::getOrCreateEmailRequest($email);

        $emailRequest->setRedirectUrl($emailRequest->getRegisterRoute());

        return $emailRequest;
    }

    public static function getOrCreateEmailRequest($email)
    {
        $emailRequest = EmailRequest::where('email', $email)->first();

        if (!$emailRequest) {
            $emailRequest = new EmailRequest();
            $emailRequest->email = $email;
            $emailRequest->save();
        }

        return $emailRequest;
    }

    public function setRedirectUrl($redirectUrl)
    {
        $this->redirect_url = $redirectUrl;
        $this->save();
    }

    public function markEmailAsVerified()
    {
        if ($this->email_verified_at) {
            return;
        }

        return $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    public function sendEmailVerificationNotification()
    {
        \Notification::route('mail', $this->getEmailForVerification())
            ->notify(new \Kompo\Auth\Notifications\VerifyEmail);
    }

}
