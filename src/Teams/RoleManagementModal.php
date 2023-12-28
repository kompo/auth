<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Modal;

class RoleManagementModal extends Modal
{
	public $model = TeamRole::class;

	protected $_Title = 'Manage Role';
	protected $_Icon = 'user-circle';

	protected $team;

	public function authorizeBoot()
	{
        $this->team = auth()->user()->currentTeam;

		return auth()->user()->can('addTeamMember', $this->team);
	}

	public function body()
	{
		return [
            TeamRole::buttonGroupField(),
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
			'role' => 'required',
		];
	}
}
