<?php

namespace Kompo\Auth\Models\Teams;

use Condoedge\Utils\Models\Model;
use Kompo\Auth\Teams\Cache\PermissionCacheInvalidator;

class PermissionRole extends Model
{
    protected $table = 'permission_role';

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
        app(PermissionCacheInvalidator::class)->permissionRoleChanged($this);
    }

    /* RELATIONS */

    /* CALCULATED FIELDS */

    /* ACTIONS */

    /* ELEMENTS */
}
