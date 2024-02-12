<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Mail\TeamInvitationMail;
use Kompo\Auth\Models\Teams\TeamRole;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TeamInvitationForm extends TeamBaseForm
{
    protected $_Title = 'Add Team Member';
    protected $_Description = 'Add a new team member to your team, allowing them to collaborate with you.';

    public function handle()
    {
        $user = auth()->user();
        $team = $user->currentTeam;
        $email = request('email');
        $role = request('role');

        Gate::forUser($user)->authorize('addTeamMember', $team);

        $this->validate($team, $email, $role);

        $invitation = $team->teamInvitations()->forceCreate([
            'email' => $email,
            'role' => $role,
        ]);

        Mail::to($email)->send(new TeamInvitationMail($invitation));
    }

    protected function body()
    {
        $teamOwner = auth()->user()->currentTeam->owner;

        return [
            _Html('Please provide the email address of the person you would like to add to this team.')->class('max-w-xl text-sm text-gray-600 mb-4'),
            _Input('Email')->name('email')->type('email'),
            TeamRole::buttonGroupField(),
            _FlexEnd(
                _SubmitButton('Send invite')->alert('Invite Sent!')->refresh()
            )
        ];
    }

    /**
     * Validate the invite member operation.
     *
     * @param  mixed  $team
     * @param  string  $email
     * @param  string|null  $role
     * @return void
     */
    protected function validate($team, string $email, ?string $role)
    {
        Validator::make([
            'email' => $email,
            'role' => $role,
        ], $this->invitationRules($team), [
            'email.unique' => __('This user has already been invited to the team.'),
        ])->after(
            $this->ensureUserIsNotAlreadyOnTeam($team, $email)
        )->validateWithBag('addTeamMember');
    }

    /**
     * Get the validation rules for inviting a team member.
     *
     * @param  mixed  $team
     * @return array
     */
    protected function invitationRules($team)
    {
        return array_filter([
            'email' => ['required', 'email', Rule::unique('team_invitations')->where(function ($query) use ($team) {
                $query->where('team_id', $team->id);
            })],
            'role' => ['required', 'string', 'in:'.implode(',', array_keys(TeamRole::usableRoles()->toArray()))]
        ]);
    }

    /**
     * Ensure that the user is not already on the team.
     *
     * @param  mixed  $team
     * @param  string  $email
     * @return \Closure
     */
    protected function ensureUserIsNotAlreadyOnTeam($team, string $email)
    {
        return function ($validator) use ($team, $email) {
            $validator->errors()->addIf(
                $team->hasUserWithEmail($email),
                'email',
                __('This user already belongs to the team.')
            );
        };
    }
}