<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Facades\Gate;
use Kompo\Auth\Mail\TeamInvitationMail;
use Kompo\Auth\Models\Teams\TeamInvitation;
use Kompo\Auth\Models\Teams\TeamRole;

class TeamInvitationForm extends TeamBaseForm
{
    protected $_Title = 'Add Team Member';
    protected $_Description = 'Add a new team member to your team, allowing them to collaborate with you.';

    public function handle()
    {
        $user = auth()->user();
        $team = currentTeam();
        $email = request('email');
        $roles = request('roles') ?: [];

        Gate::forUser($user)->authorize('addTeamMember', $team);

        if (TeamInvitation::where('team_id', currentTeamId())->where('email', $email)->count()) {
            throwValidationError ('email', 'This user has already been invited to the team. Please cancel invitation to reinvite');
        }

        if ($team->hasUserWithEmail($email)) {
            throwValidationError ('email', 'This user already belongs to the team.');
        }

        $invitation = $team->teamInvitations()->forceCreate([
            'email' => $email,
            'role' => implode(TeamRole::ROLES_DELIMITER, $roles),
        ]);

        \Mail::to($email)->send(new TeamInvitationMail($invitation));
    }

    protected function body()
    {
        return [
            _Html('Please provide the email address of the person you would like to add to this team.')->class('max-w-xl text-sm text-gray-600 mb-4'),
            _Input('Email')->name('email')->type('email'),
            TeamRole::buttonGroupField(),
            _FlexEnd(
                _SubmitButton('Send invite')->alert('Invite Sent!')->refresh()->refresh('team-invitations-list'),
            )
        ];
    }

    public function rules()
    {
        return [
            'email' => baseEmailRules(),
            'roles' => TeamRole::teamRoleRules(),
        ];
    }
}