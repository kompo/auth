<?php

namespace Kompo\Auth\Auth;

use Kompo\Auth\Common\ImgFormLayout;

class LoginForm extends ImgFormLayout
{
    public $submitTo = 'login';

    protected $imgUrl = 'images/login-image.png';

    protected $email;
    
    public function created()
    {
        $this->email = $this->prop('email');

        $this->redirectTo = $this->prop('redirect_to') ?: 'dashboard';
    }

	public function rightColumnBody()
	{
		return [
            session('status') ? //See ResetPasswordForm: to confirm the password has been reset...
                _Html(session('status'))->class('mb-4 p-4 font-medium text-sm bg-green-100 text-green-600') :
                null,

			_Input('Email')->name('email')->default($this->email),
			_Password('Password')->name('password'),
            _Checkbox('Remember me')->name('remember'),
			_FlexEnd(
                _Link('Forgot your password?')
                    ->href('password.request')
                    ->class('text-gray-600 text-sm'),
                _SubmitButton('Login')->redirect($this->redirectTo),
            )->class('space-x-4')
		];
	}

    public function rules()
    {
        return [
            'email' => 'required|string',
            'password' => 'required|string',
        ];
    }
}
