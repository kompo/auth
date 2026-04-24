<?php

namespace Kompo\Auth\Teams;

class TeamRoleSwitcherScope
{
    public function __construct(
        public readonly string $key,
        public readonly string $roleId,
        public readonly string $roleLabel,
        public readonly int $rootTeamId,
        public readonly object $rootTeam,
        public readonly array $visibleTeamIdsIndex,
        public readonly array $switchableTeamIdsIndex,
        public readonly int $rootDepth = 0,
    ) {}

    public function containsTeam(int $teamId): bool
    {
        return isset($this->visibleTeamIdsIndex[$teamId]);
    }

    public function containsSwitchableTeam(int $teamId): bool
    {
        return isset($this->switchableTeamIdsIndex[$teamId]);
    }

    public function teamIds(): array
    {
        return array_map('intval', array_keys($this->visibleTeamIdsIndex));
    }

    public function switchableTeamIds(): array
    {
        return array_map('intval', array_keys($this->switchableTeamIdsIndex));
    }
}
