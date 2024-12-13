<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Model;

class PermissionTeamRole extends Model
{
    protected $table = 'permission_team_role';
    
    /* RELATIONS */

    /* SCOPES */
    public function scopeForTeamRole($query, $teamRoleId)
    {
        $query->where('team_role_id', $teamRoleId);
    }

    public function scopeForPermission($query, $permissionId)
    {
        $query->where('permission_id', $permissionId);
    }

    public static function scopeValid($query)
    {
        return $query->where('permission_type', '!=', PermissionTypeEnum::DENY);
    }

    public static function scopeDenied($query)
    {
        return $query->where('permission_type', PermissionTypeEnum::DENY);
    }

    /* CALCULATED FIELDS */

    /* ACTIONS */

    /* ELEMENTS */
}
