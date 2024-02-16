<?php

namespace Kompo\Auth\Auth;

use App\Models\User;
use Kompo\Auth\Common\ImgFormLayout;
use Kompo\Auth\Models\Teams\EmailRequest;

class BaseEmailForm extends ImgFormLayout
{
    public function handle()
    {
        $email = request('email');

        if ($user = User::where('email', $email)->first()) {

            return redirect()->route('login.password', ['email' => $email]);

        } else {

            $emailRequest = EmailRequest::getOrCreateEmailRequest($email);

            if (!$emailRequest->hasVerifiedEmail()) {

                $emailRequest->sendEmailVerificationNotification();

                return redirect()->route('check.verify.email');

            } else {

                return redirect()->to($emailRequest->getRegisterRoute());
            }
        }
    }

	public function rightColumnBody()
	{
		return _Rows(
            _Input('auth.email')->name('email'),
            _SubmitButton('auth.base-email-btn'),
        );
	}

    public function rules()
    {
        return [
            'email' => baseEmailRules(),
        ];
    }
}
