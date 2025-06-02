<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Teams\BaseRoles\SuperAdminRole;
use Kompo\Auth\Models\Teams\PermissionTeamRole;
use Kompo\Auth\Models\Teams\TeamRole;

/**
 * HasTeamsTrait
 * 
 * Provides team-based authorization functionality for User models.
 * 
 * This trait manages:
 * - Team membership and user roles within teams
 * - Current team/role selection for the user
 * - Permission checking across teams
 * - Team hierarchy traversal
 *
 * Flow of Team-Based Authorization:
 * 1. User belongs to multiple teams (via TeamRole records)
 * 2. User sets current team role to establish context
 * 3. Permissions are checked against current team or specified team
 * 4. Team hierarchies are considered in permission resolution
 * 5. Permission results are cached for performance
 */
trait HasTeamsTrait
{
    use HasTeamsRelations;
    use HasTeamNavigation;
    use HasTeamActions;
    use HasTeamPermissions;
}
