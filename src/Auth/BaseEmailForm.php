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
            _Rows(
                _Link('register.register-with-google')->button()->outlined()->class('shadow-md mb-2 !bg-transparent')
                    ->href('login.sso', ['service' => 'google']),
                _Link('register.register-with-microsoft')->button()->outlined()->class('shadow-md !bg-transparent')
                    ->href('login.sso', ['service' => 'azure'])
            )->class('mb-6'),
            _Input('auth-my-email')->name('email'),
            _SubmitButton('auth-base-email-btn'),
        ];
	}

    public function rules()
    {
        return [
            'email' => baseEmailRules(),
        ];
    }
}
