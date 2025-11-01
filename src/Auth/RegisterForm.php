<?php

namespace Kompo\Auth\Auth;

use Condoedge\Utils\Kompo\Common\ImgFormLayout;
use Kompo\Auth\Facades\UserModel;
use Kompo\Auth\Models\Teams\EmailRequest;

class RegisterForm extends ImgFormLayout
{
    public $model = UserModel::class;

    protected $imgUrl = 'images/register-image.png';

    public function created()
    {
        $this->emailRequestId = $this->prop('email_request_id');

        $this->emailRequest = EmailRequest::findOrFail($this->emailRequestId);

        $this->emailRequest->markEmailAsVerified();

        $user = UserModel::where('email', $this->emailRequest->getEmailForVerification())->first();

        if ($user) {
            // If there is a registered user, setting the model to that user
            $this->model($user);

            auth()->login($this->model);
        }
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

        auth()->login($this->model);
    }

    public function response()
    {
        return redirect()->route('dashboard');
    }

	public function rightColumnBody()
	{
        if ($this->model->id) {
            return _Rows(
                _Html('auth-you-are-already-registered-and-logged-in')->class('mb-6'),

                _Link2Button('auth-go-to-the-dashboard-page')->href('dashboard'),
            );
        }

		return [
            _Rows(
                _Html($this->emailRequest->getEmailForVerification()),
                _Html('auth-your-email-has-been-verified!'),
            )->class('mb-6'),
			_InputRegisterNames(),
            _InputRegisterPasswords(),
            _CheckboxTerms(),
			_SubmitButton('auth-register')->class('mb-4'),
            // _LinkAlreadyHaveAccount(),
		];
	}

    public function rules()
    {
        return registerRules();
    }
}
