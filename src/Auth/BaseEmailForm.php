<?php

namespace Kompo\Auth\Auth;

use App\Models\User;
use Kompo\Auth\Common\ImgFormLayout;
use Kompo\Auth\Models\Teams\EmailRequest;

class BaseEmailForm extends ImgFormLayout
{
    protected $imgUrl = 'images/base-email-image.png';

    public function handle()
    {
        $email = request('email');

        if ($user = User::where('email', $email)->first()) {

            return redirect()->route('login.password', ['email' => $email]);

        } else {

            $emailRequest = EmailRequest::getOrCreateWithRegisterUrl($email);
            
            $emailRequest->sendEmailVerificationNotification();

            return redirect()->to(\Url::signedRoute('check.verify.email', ['id' => $emailRequest]));

            //if (!$emailRequest->hasVerifiedEmail()) { //commented this out to force verification every time otherwise somebody can use a verified email to create an account

            //} else {

                //return redirect()->to($emailRequest->getRegisterRoute());
            //}
        }
    }

	public function rightColumnBody()
	{
		return [
            _Input('ka::auth.email')->name('email'),
            _SubmitButton('ka::auth.base-email-btn'),
        ];
	}

    public function rules()
    {
        return [
            'email' => baseEmailRules(),
        ];
    }
}
