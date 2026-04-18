<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Collection;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Teams\Contracts\TeamRoleAccessDataSourceInterface;

class TeamRoleAccessDataSource implements TeamRoleAccessDataSourceInterface
{
    public function activeTeamRoles($user, int|string|null $profile): Collection
    {
        $teamSelect = ['teams.id', 'teams.team_name', 'teams.parent_team_id'];

        if ($this->hasCommitteeColumn()) {
            $teamSelect[] = 'teams.is_committee';
        }

        return TeamRole::withoutGlobalScope('authUserHasPermissions')
            ->select(['team_roles.id', 'team_roles.user_id', 'team_roles.team_id', 'team_roles.role', 'team_roles.role_hierarchy'])
            ->where('team_roles.user_id', $user->id)
            ->whereHas('team')
            ->whereHas('roleRelation', fn($query) => $query->when(
                $profile !== null && $profile !== '',
                fn($query) => $query->where('profile', $profile)
            ))
            ->with([
                'team' => fn($query) => $query->select($teamSelect),
                'roleRelation' => fn($query) => $query->select(['roles.id', 'roles.name', 'roles.profile']),
            ])
            ->get();
    }

    public function teamForAccessCheck(int $teamId)
    {
        return TeamModel::withoutGlobalScope('authUserHasPermissions')
            ->select(['teams.id', 'teams.team_name', 'teams.parent_team_id'])
            ->find($teamId);
    }

    public function roleName(string $roleId): string
    {
        $role = RoleModel::withoutGlobalScope('authUserHasPermissions')->find($roleId);

        return $role?->name ?: $roleId;
    }

    private function hasCommitteeColumn(): bool
    {
        return hasColumnCached('teams', 'is_committee');
    }
}
