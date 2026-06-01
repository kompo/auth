<?php

namespace Kompo\Auth\Teams\Roles;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool canAssignToUser($actor, $userId)
 * @method static bool canAssignToTeam($actor, $teamId)
 * @method static bool canAssignRole($actor, $roleId)
 * @method static bool actorBypassesRestrictions($actor)
 * @method static bool isRoleValidForTeam($roleId, $teamId)
 * 
 * @mixin \Kompo\Auth\Teams\Roles\TeamRoleAssignmentGuard
 */
class TeamRoleAssignmentGuardFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return TeamRoleAssignmentGuard::class;
    }
}