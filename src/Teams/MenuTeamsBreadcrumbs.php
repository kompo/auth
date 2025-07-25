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

		return _Flex(
			$teams->map(fn($team, $key) => $this->getTeamSwitcherLink($team, $key >= 1)),
		);
	}

	protected function getTeamSwitcherLink($team, $withSlash = false)
	{
		$label = ($withSlash ? '&nbsp;&nbsp;/&nbsp;&nbsp;' : '').$team->team_name;

		return $team->getTeamSwitcherLink($label);
	}

    public function switchToTeamRole($teamId)
    {
        if (!auth()->user()->switchToFirstTeamRole($teamId)) {
			abort(403, __('auth-you-dont-have-access-to-this-team'));
		}

		return refresh();
    }
}
