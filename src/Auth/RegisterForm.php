<?php

namespace Kompo\Auth\Auth;

use App\Models\User;
use Kompo\Auth\Models\Teams\EmailRequest;
use Kompo\Form;

class RegisterForm extends Form
{
    public $model = User::class;

    public $containerClass = 'container min-h-screen flex flex-col sm:justify-center items-center';
    public $class = 'sm:mx-auto sm:w-full sm:max-w-md';

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

	public function render()
	{
		return [
            _Rows(
                _Html($this->emailRequest->getEmailForVerification()),
                _Html('Your email has been verified!'),
            ),
			_Input('auth.name')->name('name'),
			_Password('auth.password-auth')->name('password'),
			_Password('auth.password-auth-confirmation')->name('password_confirmation', false),
            _Checkbox('auth.i-agree-to-the-terms-of-service-and-privacy-policy')->name('terms', false),
			_SubmitButton('auth.register')->class('mb-4'),
            _LinkAlreadyHaveAccount(),
		];
	}

    public function rules()
    {
        return registerRules();
    }
}
