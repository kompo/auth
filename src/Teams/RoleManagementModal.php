<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Modal;
use App\Models\User;

class RoleManagementModal extends Modal
{
	public $model = User::class;

	protected $_Title = 'Manage Role';
	protected $_Icon = 'user-circle';

	protected $team;

	public function authorizeBoot()
	{
        $this->team = currentTeam();

		return auth()->user()->can('addTeamMember', $this->team);
	}

	public function handle()
	{
		if (config('kompo-auth.team_hierarchy_roles')) {
            $this->updateForHierarchyRoles();
        } else {
            if (config('kompo-auth.multiple_roles_per_team')) {
                $this->updateForMultipleRolePerTeam();
            } else {
                $this->updateForSingleRolePerTeam();
            }
        }
	}

    protected function updateForSingleRolePerTeam()
    {
		$oldRole = $this->model->teamRoles()->forTeam($this->team->id)->first();

		$oldRole->role = request('role');
		$oldRole->save();

		$this->model->switchToFirstTeamRole();
    }

    protected function updateForMultipleRolePerTeam()
    {
        $newRoles = collect(request('role') ?: []);

		$oldRoles = $this->model->teamRoles()->forTeam($this->team->id)->whereNotIn('role', TeamRole::baseRoles())->get();

		//Delete different roles
		$oldRoles->each(
			fn($teamRole) => !$newRoles->contains($teamRole->role) ? $teamRole->delete() : null
		);

		//Add new roles
        $newRoles->each(
        	fn($role) => !$oldRoles->pluck('role')->contains($role) ? $this->model->createTeamRole($this->team, $role) : null
        );

        if (!$this->model->teamRoles()->pluck('id')->contains($this->model->current_team_role_id)) {
        	$this->model->switchToFirstTeamRole();
        }
    }

    protected function updateForHierarchyRoles()
    {
        $newRoles = collect(request('multi_roles') ?: []);
        $roleIds = $newRoles->map(fn($mr) => $mr['multiFormKey'] ?? null);

		$oldRoles = $this->model->teamRoles()->forTeam($this->team->id)->whereNotIn('role', TeamRole::baseRoles())->get();

		//Delete different roles
		$oldRoles->each(
			fn($teamRole) => !$roleIds->contains($teamRole->id) ? $teamRole->delete() : null
		);

		//Add new roles
        $newRoles->each(
        	fn($role) => !($role['multiFormKey'] ?? null) ? $this->model->createTeamRole($this->team, $role['role'], $role['role_hierarchy']) : null
        );

        if (!$this->model->teamRoles()->pluck('id')->contains($this->model->current_team_role_id)) {
        	$this->model->switchToFirstTeamRole();
        }
    }

    public function deleteTeamRole($id)
    {
        TeamRole::findOrFail($id)->delete();
    }

	public function body()
	{
		return [
			!config('kompo-auth.team_hierarchy_roles') ? 
                TeamRole::buttonGroupField()
                	->value(
                		config('kompo-auth.multiple_roles_per_team') ? 
                			$this->model->getRelatedTeamRoles($this->team->id)->pluck('role') : 
                			$this->model->getFirstTeamRole($this->team->id)->role
                	) :
                _MultiForm()->name('multi_roles', false)
                    ->formClass(TeamInvitationMultiForm::class)
                    ->preloadIfEmpty()
                    ->value($this->model->getRelatedTeamRoles($this->team->id)),
		];
	}

	public function footerButtons()
	{
		return [
			_Button('Nevermind')->outlined()->closeModal(),
			_SubmitButton('general.save')->class('ml-4'),
		];
	}

	public function rules()
	{
		return TeamRole::teamRoleRules();
	}
}
