<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Models\Teams\Team;
use Kompo\Table;

class TeamsTable extends Table
{
    protected $teamId;
    protected $team;

    public function created()
    {
        $this->teamId = $this->prop('team_id');
        if ($this->teamId) {
            $this->team = Team::findOrFail($this->teamId);
        } else {
            $this->team = Team::getMainParentTeam(currentTeam());
        }
    }

    public function query()
    {
        return $this->team->teams()->withCount('teams')->with('authUserTeamRoles');
    }

    public function top()
    {
        return _FlexBetween(
            _Flex4(
                $this->team->parentTeam ? _BackLink('Back')->href('teams.table', ['team_id' => $this->team->parent_team_id]) : null,
                _TitleMain($this->team->team_name),
            ),
        );
    }

    public function headers()
    {
        return [
            _Th('teams.team-name'),
            _Th('teams.nb-child-teams'),
            _Th('teams.address'),
//            _Th('teams.your-role'),
        ];
    }

    public function render($team)
    {
        $el = _TableRow(
            _Html($team->team_name),
            _Html($team->teams_count ?: '-'),
            _Html($team->primary_shipping_address_id ?: '-'),
//            _Html($team->authUserTeamRoles->map(fn($teamRole) => $teamRole->getRoleName())->implode(', ')),
        );

        return $team->teams_count ? $el->href('teams.table', ['team_id' => $team->id]) : $el;
    }
}
