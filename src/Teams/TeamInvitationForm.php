<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Facades\Gate;
use Kompo\Auth\Teams\Mail\TeamInvitationMail;
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

        Gate::forUser($user)->authorize('addTeamMember', $team);

        if (TeamInvitation::where('team_id', currentTeamId())->where('email', $email)->count()) {
            throwValidationError ('email', 'This user has already been invited to the team. Please cancel invitation to reinvite');
        }

        if ($team->hasUserWithEmail($email)) {
            throwValidationError ('email', 'This user already belongs to the team.');
        }

        if (config('kompo-auth.team_hierarchy_roles')) {
            $invitation = $this->createForHierarchyRoles($user, $team, $email);
        } else {
            if (config('kompo-auth.multiple_roles_per_team')) {
                $invitation = $this->createForMultipleRolePerTeam($user, $team, $email);
            } else {
                $invitation = $this->createForSingleRolePerTeam($user, $team, $email);
            }
        }

        \Mail::to($email)->send(new TeamInvitationMail($invitation));
    }

    protected function createForSingleRolePerTeam($user, $team, $email)
    {
        $role = request('role');

        return $team->teamInvitations()->forceCreate([
            'email' => $email,
            'role' => $role,
        ]);
    }

    protected function createForMultipleRolePerTeam($user, $team, $email)
    {
        $roles = request('role' ?: []);

        return $team->teamInvitations()->forceCreate([
            'email' => $email,
            'role' => implode(TeamRole::ROLES_DELIMITER, $roles),
        ]);
    }

    protected function createForHierarchyRoles($user, $team, $email)
    {
        $roles = collect(request('multi_roles') ?: [])->map(fn($mr) => $mr['role'])->toArray();
        $hierarchies = collect(request('multi_roles') ?: [])->map(fn($mr) => $mr['role_hierarchy'])->toArray();

        return $team->teamInvitations()->forceCreate([
            'email' => $email,
            'role' => implode(TeamRole::ROLES_DELIMITER, $roles),
            'role_hierarchy' => implode(TeamRole::ROLES_DELIMITER, $hierarchies),
        ]);
    }

    protected function body()
    {
        return [
            _Html('Please provide the email address of the person you would like to add to this team.')->class('max-w-xl text-sm text-gray-600 mb-4'),
            _Input('auth-email')->name('email')->type('email'),
            !config('kompo-auth.team_hierarchy_roles') ? 
                TeamRole::buttonGroupField() :
                _MultiForm()->name('multi_roles', false)
                    ->formClass(TeamInvitationMultiForm::class)
                    ->preloadIfEmpty(),
            _FlexEnd(
                _SubmitButton('Send invite')->alert('Invite Sent!')->refresh()->refresh('team-invitations-list'),
            )
        ];
    }

    public function rules()
    {
        $rules = [];

        $rules['email'] = baseEmailRules();

        return $rules;
    }
}