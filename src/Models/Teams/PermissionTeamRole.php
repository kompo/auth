<?php

namespace Kompo\Auth\Models\Teams;

use Condoedge\Utils\Models\Model;
use Kompo\Auth\Teams\PermissionCacheManager;

class PermissionTeamRole extends Model
{
    protected $table = 'permission_team_role';

    public static function booted()
    {
        parent::booted();

        static::saved(function ($permissionTeamRole) {
            $permissionTeamRole->clearCache();
        });

        static::deleted(function ($permissionTeamRole) {
            $permissionTeamRole->clearCache();
        });
    }

    protected function clearCache()
    {
        app(PermissionCacheManager::class)->invalidateByChange('team_role_changed', [
            'team_role_ids' => [$this->team_role_id]
        ]);
    }
    
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
