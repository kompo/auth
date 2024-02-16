<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\ModelBase;

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
        return \URL::temporarySignedRoute('register', [
            'email_request_id' => $this->id,
        ]);
    }

    /* ACTIONS */
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
