<?php

namespace Kompo\Auth\Teams;

class TeamRoleSwitcherNodeFactory
{
    public function __construct(
        private TeamRoleSwitcherTeamRepository $teams,
    ) {}

    public function make(
        $team,
        array $access,
        string $mode,
        array $currentPathIds,
        int $childrenCount,
        int $committeeCount,
        $currentTeamRole = null,
    ): array {
        $teamId = (int) $team->id;
        $isCommittee = $this->teams->isCommittee($team);
        $isSelectable = $this->teams->isSelectableForMode($team, $mode);

        return [
            'id' => $this->nodeId($teamId),
            'teamId' => $teamId,
            'parentId' => $team->parent_team_id ? (int) $team->parent_team_id : null,
            'name' => $team->team_name,
            'parentName' => $team->relationLoaded('parentTeam') ? $team->parentTeam?->team_name : null,
            'isCurrent' => $isSelectable && $currentTeamRole && $currentTeamRole->team_id == $teamId,
            'isInCurrentPath' => in_array($teamId, $currentPathIds, true),
            'isCommittee' => $isCommittee,
            'isSelectable' => $isSelectable,
            'hasChildren' => $childrenCount > 0,
            'childrenCount' => $childrenCount,
            'committeeCount' => $committeeCount,
            'level' => $this->teams->teamLevelValue($team),
            'levelLabel' => $this->teams->teamLevelLabel($team, $isCommittee),
            'levelKey' => $this->teams->teamLevelKey($team, $isCommittee),
            'roles' => $access['roles'] ?? [],
            'switchRole' => $access['switchRole'] ?? null,
        ];
    }

    public function nodeId(int $teamId): string
    {
        return 'team-' . $teamId;
    }
}
