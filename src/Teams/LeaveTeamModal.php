<?php

namespace Kompo\Auth\Teams;

use Kompo\Modal;

class LeaveTeamModal extends Modal
{
	protected $_Title = 'Leave Team';
	protected $_Icon = 'user-circle';

	public function handle()
	{
		currentTeam()->detachFromTeam(auth()->user());

        \Auth::guard()->logout();

        return redirect('/');
	}

	public function body()
	{
		return [
			_Html('Are you sure you would like to leave this team?')
		];
	}

	public function footerButtons()
	{
		return [
			_Button('Nevermind')->outlined()->closeModal(),
			_SubmitButton('Oui')->class('ml-4')->redirect(),
		];
	}
}
