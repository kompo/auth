<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Teams\TeamRole;


/**
 * Handles navigation between teams and roles
 * Optimized with proper cache management
 */
trait HasTeamNavigation
{
    /**
     * Switch to first available team role for a team
     */
    public function switchToFirstTeamRole($teamId = null): bool
    {
        $teamRole = $this->getFirstTeamRole($teamId);

        if (!$teamRole) {
            return false;
        }

        return $this->switchToTeamRole($teamRole);
    }

    /**
     * Switch to team role by ID
     */
    public function switchToTeamRoleId($teamRoleId): bool
    {
        $teamRole = TeamRole::find($teamRoleId);
        
        if (!$teamRole) {
            return false;
        }
        
        return $this->switchToTeamRole($teamRole);
    }

    /**
     * Switch to a specific team role
     */
    public function switchToTeamRole($teamRole): bool
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

    /**
     * Switch role within current team (legacy method)
     */
    public function switchRole($role): void
    {
        $availableRole = $this->teamRoles()
            ->where('team_id', $this->currentTeamRole->team_id)
            ->where('role', $role)
            ->first();

        if (!$availableRole) {
            abort(403, __('This role is not available to this user!'));
        }

        $this->switchToTeamRole($availableRole);
    }


    /**
     * Check if user can access a specific team through any role
     */
    public function canAccessTeam($teamId): bool
    {
        return $this->hasAccessToTeam($teamId);
    }

    /**
     * Get available teams for role switching
     */
    public function getAvailableTeamsForSwitching($profile = 1, $search = ''): \Illuminate\Support\Collection
    {
        return collect($this->getAllTeamIdsWithRolesCached($profile, $search));
    }

    /**
     * Switch to a team with specific role
     */
    public function switchToTeamWithRole($teamId, $roleId): bool
    {
        // Check if user has access to this team with this role
        if (!$this->hasAccessToTeam($teamId, $roleId)) {
            return false;
        }

        // Get or create the team role
        $teamRole = TeamRole::getOrCreateForUser($teamId, $this->id, $roleId);
        
        if (!$teamRole) {
            return false;
        }

        return $this->switchToTeamRole($teamRole);
    }

    /**
     * Get navigation breadcrumb for current team hierarchy
     */
    public function getTeamNavigationBreadcrumb(): \Illuminate\Support\Collection
    {
        $currentTeam = $this->currentTeamRole?->team;
        
        if (!$currentTeam) {
            return collect();
        }

        $breadcrumb = collect([$currentTeam]);
        $team = $currentTeam;

        // Walk up the hierarchy
        while ($team->parentTeam) {
            $breadcrumb->prepend($team->parentTeam);
            $team = $team->parentTeam;
        }

        return $breadcrumb;
    }

    /**
     * Emergency method to reset user to a valid team role
     */
    public function resetToValidTeamRole(): bool
    {
        // Clear any invalid current team role
        $this->current_team_role_id = null;
        $this->save();

        // Try to switch to first available team role
        $firstRole = $this->teamRoles()->with(['team'])->whereHas('team')->first();
        
        if ($firstRole) {
            return $this->switchToTeamRole($firstRole);
        }

        // If no team roles exist, create personal team
        $personalTeam = $this->createPersonalTeamAndOwnerRole();
        $ownerRole = $this->teamRoles()
            ->where('team_id', $personalTeam->id)
            ->first();

        if ($ownerRole) {
            return $this->switchToTeamRole($ownerRole);
        }

        return false;
    }

    /**
     * Validate that current team role setup is valid
     */
    public function validateCurrentTeamRole(): array
    {
        $issues = [];
        
        if (!$this->current_team_role_id) {
            $issues[] = 'No current team role set';
        } else {
            $currentRole = $this->currentTeamRole;
            
            if (!$currentRole) {
                $issues[] = 'Current team role ID points to non-existent role';
            } elseif (!$currentRole->team) {
                $issues[] = 'Current team role points to deleted team';
            } elseif ($currentRole->terminated_at) {
                $issues[] = 'Current team role is terminated';
            } elseif ($currentRole->suspended_at) {
                $issues[] = 'Current team role is suspended';
            }
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'current_team_role_id' => $this->current_team_role_id,
            'available_roles_count' => $this->teamRoles()->count()
        ];
    }

    /**
     * Auto-fix current team role if invalid
     */
    public function autoFixCurrentTeamRole(): bool
    {
        $validation = $this->validateCurrentTeamRole();
        
        if ($validation['valid']) {
            return true; // Already valid
        }

        Log::info('Auto-fixing invalid team role for user', [
            'user_id' => $this->id,
            'issues' => $validation['issues']
        ]);

        return $this->resetToValidTeamRole();
    }
}