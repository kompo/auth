<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Form;

class MenuTeamsBreadcrumbs extends Form
{
	public function render()
	{
		$team = currentTeam();

		if (!$team) {
			return;
		}

		$teams = collect([$team]);

		while ($team->parentTeam) {
			$teams = $teams->prepend($team->parentTeam);
			$team = $team->parentTeam;
		}

		return _Flex4(
			$teams->map(fn($team) => $this->getTeamSwitcherLink($team)),
		);
	}

	protected function getTeamSwitcherLink($team)
	{
		return _Link($team->team_name)->class(currentTeam()->id == $team->id ? 'font-bold' : '')
			->selfPost('switchToTeamRole', ['team_id' => $team->id])
			->redirect();
	}

    public function switchToTeamRole($teamId)
    {
        auth()->user()->switchToFirstTeamRole($teamId);

        return redirect()->route('dashboard');
    }
}
