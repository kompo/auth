<?php

namespace Kompo\Auth\Account;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Kompo\Form;

class UpdatePasswordForm extends Form
{
    public $class = 'max-w-xl w-full mx-auto';

    public function created()
    {
        $this->model(auth()->user());
    }

    public function beforeFill()
    {
        if (! Hash::check(request('current_password'), $this->model->password)) {
            throw ValidationException::withMessages([
               'current_password' => [__('The provided password does not match your current password.')],
            ]);
        }
    }

    public function response()
    {
        session()->flash('update-password', 'Your password has been updated!');

        return redirect()->back();
    }

    public function render()
    {
		return [
            session('update-password') ?
                _Html(session('update-password'))->class('bg-green-100 rounded-lg p-4 mb-4 font-medium text-sm text-green-600') :
                null,

            _Password('Current Password')
                ->name('current_password', false)->required(),
			_PasswordInput('Password')
                ->name('password')->required(),
            _PasswordInput('Confirm Password')
                ->name('password_confirmation', false),

			_FlexEnd(
                _SubmitButton('general.save')
                    ->inPanel('password-updated-response')
            )
		];
	}

    public function rules()
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => passwordRules(),
        ];
    }
}
