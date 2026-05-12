<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Facades\RoleModel;
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
 */
class TeamRoleAssignmentGuard
{
    public static function actorBypassesRestrictions($actor): bool
    {
        if (!$actor) {
            return false;
        }

        return isSuperAdmin() || isImpersonated() || $actor->hasRole('super-admin');
    }

    public static function canAssignRole($actor, $roleId): bool
    {
        if (!$actor || !$roleId) {
            return false;
        }

        return RoleModel::query()
            ->where('id', $roleId)
            ->availableForUserPermissions($actor) // By default avoiding super-admin if actorBypassesRestrictions returns false
            ->exists();
    }

    public static function canAssignToUser($actor, $targetUserId): bool
    {
        if (!$actor || !$targetUserId) {
            return false;
        }

        if ((int) $targetUserId === (int) $actor->id) {
            return static::actorBypassesRestrictions($actor);
        }

        return true;
    }

    public static function canAssignToTeam($actor, $targetTeamId): bool
    {
        if (!$actor || !$targetTeamId) {
            return false;
        }

        return collect($actor->getAllAccessibleTeamIds())->contains($targetTeamId);
    }

    /**
     * Throw 403 unless the actor may assign $teamRole's role to $teamRole's user.
     */
    public static function assertCanAssign($actor, TeamRole $teamRole): void
    {
        if (!static::canAssignRole($actor, $teamRole->role)) {
            abort(403, __('auth-you-cannot-assign-this-role'));
        }

        if (!static::canAssignToUser($actor, $teamRole->user_id)) {
            abort(403, __('auth-you-cannot-assign-this-role-to-this-user'));
        }

        if (!static::canAssignToTeam($actor, $teamRole->team_id)) {
            abort(403, __('auth-you-cannot-assign-this-role-in-this-team'));
        }
    }
}
