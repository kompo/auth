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
		$newRoles = collect(request('roles') ?: []);

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

	public function body()
	{
		return [
            TeamRole::buttonGroupField()->value($this->model->collectAvailableRoles()),
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
		return [
			'roles' => TeamRole::teamRoleRules(),
		];
	}
}
