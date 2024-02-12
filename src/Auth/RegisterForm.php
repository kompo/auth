<?php

namespace Kompo\Auth\Auth;

use App\Models\User;
use Kompo\Form;
use Laravel\Fortify\Rules\Password;
use Illuminate\Auth\Events\Registered;

class RegisterForm extends Form
{
    public $model = User::class;

    public $containerClass = 'container min-h-screen flex flex-col sm:justify-center items-center';
    public $class = 'sm:mx-auto sm:w-full sm:max-w-md';

    public function completed()
    {
        $team = $this->model->createPersonalTeamAndOwnerRole();

        $this->model->switchTeam($team);

        event(new Registered($this->model));

        auth()->guard()->login($this->model);
    }

    public function response()
    {
        return redirect()->route('dashboard');
    }

	public function render()
	{
		return [
			_Input('auth.name')->name('name'),
            _Input('auth.email')->name('email'),
			_Password('auth.password-auth')->name('password'),
			_Password('auth.password-auth-confirmation')->name('password_confirmation', false),
            _Checkbox('auth.i-agree-to-the-terms-of-service-and-privacy-policy')->name('terms', false),
			_SubmitButton('auth.register')->class('mb-4'),
            _LinkAlreadyHaveAccount(),
		];
	}

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => static::passwordRules(),
            'terms' => ['required', 'accepted'],
        ];
    }

    public static function passwordRules() //Extracted into it's own method to reuse in ResetPasswordForm
    {
        return ['required', 'string', new Password, 'confirmed'];
    }
}
