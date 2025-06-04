<?php

namespace Kompo\Auth\Models\Teams;

use Condoedge\Utils\Models\Model;

class PermissionRole extends Model
{
    public static function booted()
    {
        parent::booted();

        static::saved(function ($permissionRole) {
            $permissionRole->clearCache();
        });

        static::deleted(function ($permissionRole) {
            $permissionRole->clearCache();
        });
    }

    protected function clearCache()
    {
        app(\Kompo\Auth\Teams\PermissionCacheManager::class)->invalidateByChange('role_permissions_changed', [
            'role_ids' => [$this->role_id]
        ]);
    }

    /* RELATIONS */

    /* CALCULATED FIELDS */

    /* ACTIONS */

    /* ELEMENTS */
}
