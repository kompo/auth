<?php

namespace Kompo\Auth\Auth;

use Condoedge\Utils\Kompo\Common\ImgFormLayout;
use Illuminate\Support\Facades\URL;
use Kompo\Auth\Facades\UserModel;
use Kompo\Auth\Models\Teams\EmailRequest;

class BaseEmailForm extends ImgFormLayout
{
    protected $imgUrl = 'images/base-email-image.png';

    public function handle()
    {
        $email = request('email');

        if ($user = UserModel::where('email', $email)->first()) {

            return redirect()->route('login.password', ['email' => $email]);

        } else {

            $emailRequest = EmailRequest::getOrCreateWithRegisterUrl($email);

            $emailRequest->sendEmailVerificationNotification();

            return redirect()->to(URL::signedRoute('check.verify.email', ['id' => $emailRequest]));

            //if (!$emailRequest->hasVerifiedEmail()) { //commented this out to force verification every time otherwise somebody can use a verified email to create an account

            //} else {

                //return redirect()->to($emailRequest->getRegisterRoute());
            //}
        }
    }

	public function rightColumnBody()
	{
		return [
            _StatusNotice()?->class('ErrorCard'),
            
            _Rows(
//                _Link('register.login-with-google')->button()->outlined()->class('mb-2 !bg-transparent border !border-warning')
//                    ->href('login.sso', ['service' => 'google']),
                _Link('register.login-with-microsoft')->button()->outlined()->class('!bg-transparent border !border-warning')
                    ->href('login.sso', ['service' => 'azure'])
            )->class('mb-8'),
            _Input('auth-my-email')->name('email'),
            _SubmitButton('auth-base-email-btn'),
        ];
	}

    public function localeSwitcher()
    {
        if (function_exists('_LoginRightTopOverride')) {
            return _LoginRightTopOverride();
        }

        return parent::localeSwitcher();
    }

    public function rules()
    {
        return [
            'email' => baseEmailRules(),
        ];
    }
}
