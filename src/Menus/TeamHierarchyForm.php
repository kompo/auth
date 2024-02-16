<?php

namespace Kompo\Auth\Menus;

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

		return _Flex(
			$teams->map(fn($team) => _Html($team->name)->class('mr-4')),
		);
	}
}
