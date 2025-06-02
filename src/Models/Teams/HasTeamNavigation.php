<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Teams\TeamRole;

/**
 * Handles navigation between teams and roles
 */
trait HasTeamNavigation
{
    public function switchToFirstTeamRole($teamId = null)
    {
        $teamRole = $this->getFirstTeamRole($teamId);

        if (!$teamRole) {
            return false;
        }

        return $this->switchToTeamRole($teamRole);
    }

    public function switchToTeamRoleId($teamRoleId)
    {
        return $this->switchToTeamRole(TeamRole::findOrFail($teamRoleId));
    }

    public function switchToTeamRole($teamRole)
    {
        if (!$this->isOwnTeamRole($teamRole)) {
            return false;
        }

        $this->forceFill([
            'current_team_role_id' => $teamRole->id,
        ])->save();

        $this->setRelation('currentTeamRole', $teamRole);
        $this->refreshRolesAndPermissionsCache();

        return true;
    }

    public function switchRole($role)
    {
        $availableRole = $this->teamRoles()->where('team_id', $this->current_team_id)->where('role', $role)->first();

        if (!$availableRole) {
            abort(403, __('This role is not available to this user!'));
        }

        $this->forceFill([
            'current_role' => $role,
        ])->save();
    }

    public function refreshRolesAndPermissionsCache()
    {
        $currentTeamRole = $this->currentTeamRole()->first();

        try {
            \Cache::put('currentTeamRole'.$this->id, $currentTeamRole, 120);
            \Cache::put('currentTeam'.$this->id, $currentTeamRole->team, 120);
            \Cache::put('currentPermissions'.$this->id, $currentTeamRole->permissions()->pluck('complex_permission_key'), 120);            
        } catch (\Throwable $e) {
            \Log::info('Failed writing roles and permissions to cache '.$e->getMessage());
        }
    }
}