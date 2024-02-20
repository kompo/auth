<?php

namespace Kompo\Auth\Teams;

use App\Models\User;
use Kompo\Modal;

class RemoveTeamMemberModal extends Modal
{
	protected $_Title = 'Remove team mmember';
	protected $_Icon = 'user-circle';

	protected $userId;
	protected $user;

	protected $team;

	public function authorizeBoot()
	{
        $this->team = currentTeam();

		return auth()->user()->can('removeTeamMember', $this->team);
	}

    public function created()
    {
		$this->userId = $this->prop('user_id');
		$this->user = User::findOrFail($this->userId);
    }

	public function handle()
	{
		if ($this->user->id === $this->team->owner->id) {
			abort(403, __('You may not leave a team that you created.'));
		}

		$this->team->detachFromTeam($this->user);

		return __('Team member removed!');
	}

	public function body()
	{
		return [
			_Html('Are you sure you would like to remove this person from the team?')->class('bg-red-100 bg-red-700 font-bold p-4 rounded-lg text-sm'),
		];
	}

	public function footerButtons()
	{
		return [
			_Button('Nevermind')->outlined()->closeModal(),
			_SubmitButton('yes')->class('ml-4')
				->inAlert()->closeModal(),
		];
	}
}
