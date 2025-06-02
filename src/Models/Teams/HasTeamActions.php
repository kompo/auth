<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;

/**
 * Handles CRUD actions on teams and roles
 */
trait HasTeamActions
{
    public function createPersonalTeamAndOwnerRole()
    {
        $team = Team::forceCreate([
            'user_id' => $this->id,
            'team_name' => explode(' ', $this->name, 2)[0] . "'s Team",
        ]);

        $this->createTeamOwnerRole($team);

        return $team;
    }

    public function createTeamRole($team, $role, $hierarchy = null)
    {
        // Check if the role already exists
        if ($teamRole = $this->teamRoles()->where('team_id', $team->id)->where('role', $role)->first()) {
            if ($hierarchy) {
                $teamRole->role_hierarchy = $hierarchy;
                $teamRole->systemSave();
            }

            return $teamRole;
        }

        // Create new role
        $teamRole = new TeamRole();
        $teamRole->team_id = $team->id;
        $teamRole->user_id = $this->id;
        $teamRole->role = $role;
        $teamRole->role_hierarchy = $hierarchy ?: RoleHierarchyEnum::DIRECT;
        $teamRole->systemSave();

        return $teamRole;
    }

    public function createRolesFromInvitation($invitation)
    {
        $team = $invitation->team;

        $roles = explode(TeamRole::ROLES_DELIMITER, $invitation->role);
        $hierarchies = explode(TeamRole::ROLES_DELIMITER, $invitation->role_hierarchy);

        collect($roles)->each(fn($role, $key) => $this->createTeamRole($team, $role, $hierarchies[$key] ?? null));

        $this->switchToFirstTeamRole($invitation->team_id);

        $invitation->delete();
    }    /* ROLES - Methods for checking specific roles */
    public function isTeamOwner()
    {
        return $this->ownsTeam($this->currentTeamRole->team);
    }

    public function isSuperAdmin()
    {
        return in_array($this->email, config('kompo-auth.superadmin-emails', []));
    }
}
