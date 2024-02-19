<?php

namespace Kompo\Auth\Teams;

use App\Models\User;
use Kompo\Auth\Common\ImgFormLayout;
use Kompo\Auth\Models\Teams\TeamInvitation;

class TeamInvitationRegisterForm extends ImgFormLayout
{
    protected $imgUrl = 'images/register-image.png';

    public $model = User::class;

    protected $invitation;
    protected $team;

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
        $this->model->createRolesFromInvitation($this->invitation);

        //event(new Registered($this->model)); //uncomment if needed

        auth()->guard()->login($this->model);
    }

    public function response()
    {
        return redirect()->route('dashboard');
    }

    public function rightColumnBody()
    {
        return [
            _Input('Your invitation email')->name('show_email', false)->readOnly()
                ->value($this->invitation->email)->inputClass('bg-gray-50 rounded-xl'),

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
