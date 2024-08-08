<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Models\Model;

class Permission extends Model
{
    /* RELATIONS */

    /* CALCULATED FIELDS */
    public static function findByKey($permissionKey)
    {
        return Permission::where('permission_key', $permissionKey)->first();
    }

    // SCOPES 
    public function scopeForSection($query, $sectionId)
    {
        $query->where('permission_section_id', $sectionId);
    }

    /* ACTIONS */

    /* ELEMENTS */
}
