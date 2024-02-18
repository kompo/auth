<?php

namespace Kompo\Auth\Auth;

use App\Models\User;
use Kompo\Auth\Common\ImgFormLayout;
use Kompo\Auth\Models\Teams\EmailRequest;

class RegisterForm extends ImgFormLayout
{
    public $model = User::class;

    protected $imgUrl = 'images/register-image.png';

    public function created()
    {
        $this->emailRequestId = $this->prop('email_request_id');

        $this->emailRequest = EmailRequest::findOrFail($this->emailRequestId);

        $this->emailRequest->markEmailAsVerified();
    }

    public function beforeSave()
    {
        $this->model->email = $this->emailRequest->getEmailForVerification();
    }

    public function completed()
    {
        $team = $this->model->createPersonalTeamAndOwnerRole();

        $this->model->switchTeam($team);

        event(new \Illuminate\Auth\Events\Registered($this->model));

        auth()->guard()->login($this->model);
    }

    public function response()
    {
        return redirect()->route('dashboard');
    }

	public function rightColumnBody()
	{
		return [
            _Rows(
                _Html($this->emailRequest->getEmailForVerification()),
                _Html('ka::auth.Your email has been verified!'),
            ),
			_Input('ka::auth.name')->name('name'),
			_Password('ka::auth.password-auth')->name('password'),
			_Password('ka::auth.password-auth-confirmation')->name('password_confirmation', false),
            _Checkbox('ka::auth.i-agree-to-the-terms-of-service-and-privacy-policy')->name('terms', false),
			_SubmitButton('ka::auth.register')->class('mb-4'),
            _LinkAlreadyHaveAccount(),
		];
	}

    public function rules()
    {
        return registerRules();
    }
}
