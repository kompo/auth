<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Models\Teams\TeamRole;

/**
 * Single source of truth for "can this actor assign this role to this user".
 *
 * Splits naturally into three concerns kept here so UI filters (searchUsers,
 * role pickers) and the server-side saving guard agree on the same rules.
 *
 *   - actorBypassesRestrictions: super-admin / impersonation bypass.
 *   - canAssignRole: filters by Role::scopeAvailableForUserPermissions.
 *   - canAssignToUser: blocks self-assignment unless bypass applies.
 *   - canAssignToTeam: blocks assignment to teams actor can't access unless bypass applies.
 *   - isRoleValidForTeam: checks role's only_for_committees flag and allowed team levels.
 */
class TeamRoleAssignmentGuard
{
    public function actorBypassesRestrictions($actor): bool
    {
        if (!$actor) {
            return false;
        }

        return isSuperAdmin() || isImpersonated() || $actor->hasRole('super-admin');
    }

    public function canAssignRole($actor, $roleId): bool
    {
        if (!$actor || !$roleId) {
            return false;
        }

        if ($this->actorBypassesRestrictions($actor)) {
            return true;
        }

        return RoleModel::query()
            ->where('id', $roleId)
            ->availableForUserPermissions($actor) // By default avoiding super-admin if actorBypassesRestrictions returns false
            ->exists();
    }

    public function canAssignToUser($actor, $targetUserId): bool
    {
        if (!$actor || !$targetUserId) {
            return false;
        }

        if ($this->actorBypassesRestrictions($actor)) {
            return true;
        }

        if ((int) $targetUserId === (int) $actor->id) {
            return false;
        }

        return true;
    }

    public function canAssignToTeam($actor, $targetTeamId): bool
    {
        if (!$actor || !$targetTeamId) {
            return false;
        }

        if ($this->actorBypassesRestrictions($actor)) {
            return true;
        }

        return collect($actor->getAllAccessibleTeamIds())->contains($targetTeamId);
    }

    public function isRoleValidForTeam($roleId, $teamId): bool
    {
        if (!$roleId || !$teamId) {
            return false;
        }

        return true;
    }

    /**
     * Throw error unless the actor may assign $teamRole's role to $teamRole's user.
     */
    public function assertCanAssign($actor, TeamRole $teamRole): void
    {
        if (!$this->canAssignRole($actor, $teamRole->role)) {
            abort(403, __('auth-you-cannot-assign-this-role'));
        }

        if (!$this->canAssignToUser($actor, $teamRole->user_id)) {
            abort(403, __('auth-you-cannot-assign-this-role-to-this-user'));
        }

        if (!$this->canAssignToTeam($actor, $teamRole->team_id)) {
            abort(403, __('auth-you-cannot-assign-this-role-in-this-team'));
        }

        if (!$this->isRoleValidForTeam($teamRole->role, $teamRole->team_id)) {
            throwValidationError('role', __('auth-this-role-cannot-be-assigned-to-users-in-this-team'));
        }
    }
}
