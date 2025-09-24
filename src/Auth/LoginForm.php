<?php

namespace Kompo\Auth\Auth;

use Condoedge\Utils\Kompo\Common\ImgFormLayout;

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
            _StatusNotice()?->class('ErrorCard'),

            _ErrorField()->noInputWrapper()->name('error_field', false)->class('ErrorCard'),

			_Input('auth-email')->name('email')->default($this->email)->required(),
			_PasswordInput('auth-password')->name('password')->required(),
            _Checkbox('auth-remember-me')->name('remember'),
			_FlexEnd(
                _Link('auth-forgot-your-password?')
                    ->href('password.request')
                    ->class('text-gray-600 text-sm'),
                _SubmitButton('auth-login')->redirect($this->redirectTo),
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
