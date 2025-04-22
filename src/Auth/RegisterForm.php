<?php

namespace Kompo\Auth\Auth;

use App\Models\User;
use Condoedge\Utils\Kompo\Common\ImgFormLayout;
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
        
        $this->model->handleRegisterNames();
    }

    public function completed()
    {
        $team = $this->model->createPersonalTeamAndOwnerRole();

        fireRegisteredEvent($this->model);

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
                _Html('auth-your-email-has-been-verified!'),
            )->class('mb-6'),
			_InputRegisterNames(),
            _InputRegisterPasswords(),
            _CheckboxTerms(),
			_SubmitButton('auth-register')->class('mb-4'),
            _LinkAlreadyHaveAccount(),
		];
	}

    public function rules()
    {
        return registerRules();
    }
}
