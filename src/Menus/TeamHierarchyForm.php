<?php

namespace Kompo\Auth\Menus;

use Kompo\Auth\Models\Teams\Team;
use Kompo\Form;

class TeamHierarchyForm extends Form
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

		return _FlexBetween(
			_Flex4(
				$teams->map(fn($team) => $this->getTeamSwitcherLink($team)),
			),
			_FlexEnd4(
				_Html('Actings as'),
				_Html(auth()->user()->current_role),
			),
		);
	}

	protected function getTeamSwitcherLink($team)
	{
		return _Link($team->name)->class(currentTeam()->id == $team->id ? 'font-bold' : '')
			->selfPost('switchToTeam', ['team_id' => $team->id])
			->redirect();
	}

    public function switchToTeam($teamId)
    {
        $team = Team::findOrFail($teamId);

        auth()->user()->switchTeam($team);

        return redirect()->route('teams.manage');
    }
}
