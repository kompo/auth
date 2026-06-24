<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;

/**
 * Handles CRUD actions on teams and roles
 */
trait HasTeamActions
{
    /**
     * Create team owner role for a team
     */
    private function createTeamOwnerRole($team)
    {
        if (!config('kompo-auth.allow-own-team-registration', true)) {
            return null;
        }

        return $this->createTeamRole(
            $team, 
            config('kompo-auth.team-owner-role-key', 'owner'),
            RoleHierarchyEnum::DIRECT
        );
    }

    public function createPersonalTeamAndOwnerRole()
    {
        if (!config('kompo-auth.allow-own-team-registration', true)) {
            return null;
        }

        $team = Team::forceCreate([
            'user_id' => $this->id,
            'team_name' => explode(' ', $this->name, 2)[0] . "'s Team",
            'is_personal_team' => true,
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
                $teamRole->_skipAssignmentGuard = true;
                $teamRole->systemSave();
            }

            return $teamRole;
        }

        // Create new role
        $teamRole = new TeamRole();
        $teamRole->team_id = $team->id;
        $teamRole->user_id = $this->id;
        $teamRole->role = RoleModel::getOrCreate($role)->id;
        $teamRole->role_hierarchy = $hierarchy ?: RoleHierarchyEnum::DIRECT;
        $teamRole->_skipAssignmentGuard = true;
        $teamRole->systemSave();

        return $teamRole;
    }

    /* ROLES - Methods for checking specific roles */
    public function isTeamOwner()
    {
        return $this->ownsTeam($this->currentTeamRole->team);
    }

    public function isSuperAdmin()
    {
        return in_array($this->email, config('kompo-auth.superadmin-emails', []));
    }
}
