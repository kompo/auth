<?php

namespace Kompo\Auth\Teams;

use Condoedge\Utils\Kompo\Common\ImgFormLayout;
use Kompo\Auth\Models\Teams\TeamInvitation;
use Kompo\Auth\Models\User;

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

        $this->model->handleRegisterNames();
    }

    public function afterSave()
    {
        $this->model->createRolesFromInvitation($this->invitation);

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
            _Input('auth.your-invitation-email')->name('show_email', false)->readOnly()
                ->value($this->invitation->email)->inputClass('bg-gray-50 rounded-xl'),

            _InputRegisterNames(),
            _InputRegisterPasswords(),
            _CheckboxTerms(),
            _FlexEnd(
                _SubmitButton('auth.accept-invitation')
            )
        ];
    }

    public function rules()
    {
        return registerRules();
    }
}
