<?php

namespace Kompo\Auth\Account;

use Illuminate\Validation\Rule;
use Kompo\Form;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class ProfileInformationForm extends Form
{
    protected $shouldResendEmailVerification;

    public $class = 'max-w-xl w-full mx-auto';

    public function created()
    {
        $this->model(auth()->user());
    }

    public function beforeFill()
    {
        $this->shouldResendEmailVerification = $this->model instanceof MustVerifyEmail && (request('email') !== $this->model->email);
    }

    public function afterSave()
    {
        if ($this->shouldResendEmailVerification) {
            $this->updateVerifiedUser();
        }
    }

    public function response()
    {
        return redirect()->back();
    }

	public function render()
	{
		return [
			_Flex(
                _Image('Photo')->name('profile_photo')->rounded(),

                _Rows(
                    _InputRegisterNames(),

                    _Input('auth-email')->name('email')->type('email'),
                )->class('flex-auto'),
            )->class('space-x-4'),

			_FlexEnd(
                _SubmitButton('general.save')
            )
		];
	}

    public function rules()
    {
        return array_merge(namesRules(), [
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->model->id)],
            'photo' => ['nullable', /*'mimes:jpg,jpeg,png',*/ 'max:1024'], //TODO: removed because pic is json on UPDATE, so mimes validation fails...
        ]);
    }

    protected function updateVerifiedUser()
    {
        $this->model->email_verified_at = null;

        $this->model->save();

        $this->model->sendEmailVerificationNotification();
    }
}
