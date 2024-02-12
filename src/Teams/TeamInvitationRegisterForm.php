<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Models\Teams\TeamInvitation;
use App\Models\User;
use Kompo\Form;

class TeamInvitationRegisterForm extends Form
{
    public $model = User::class;

    protected $invitation;
    protected $team;

    public $containerClass = 'container min-h-screen flex flex-col sm:justify-center items-center';
    public $class = 'sm:mx-auto sm:w-full sm:max-w-md';

    public function created()
    {
        $this->invitation = TeamInvitation::findOrFail($this->prop('invitation'));
        $this->team = $this->invitation->team;

        if ($this->team->hasUserWithEmail($this->invitation->email)) {
            abort(403, __('auth.This user already belongs to the team'));
        }
    }

    public function beforeSave()
    {
        $this->model->email = $this->invitation->email; //ensures the email in the invitation is used
        $this->model->email_verified_at = now();
    }

    public function afterSave()
    {
        $this->model->createTeamRole($this->team, $this->invitation->role);
        
        $this->model->switchTeam($this->team);

        $this->invitation->delete();

        //event(new Registered($this->model)); //uncomment if needed

        auth()->guard()->login($this->model);
    }

    public function response()
    {
        return redirect()->route('dashboard');
    }

    public function render()
    {
        return [
            _Input('Email (reaonly)')->name('email')->readOnly()->value($this->invitation->email)->inputClass('bg-gray-50 rounded-xl'),

            _Input('general.name')->name('name'),
            _Password('Password')->name('password'),
            _Password('Confirm Password')->name('password_confirmation', false),
            _Checkbox('I agree to the terms of service and privacy policy')->name('terms', false),
            _FlexEnd(
                _SubmitButton('Accept Invitation')
            )
        ];
    }

    public function rules()
    {
        return registerRules();
    }
}
