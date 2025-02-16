<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Models\Model;

class Permission extends Model
{
    protected $fillable = [
        'permission_key',
        'permission_name',
        'permission_description',
        'permission_section_id',
        'object_type',
        'added_by', 
        'modified_by'
    ];

    protected $casts = [
        'object_type' => PermissionObjectTypeEnum::class,
    ];
    
    /* RELATIONS */
    public function roles()
    {
        return $this->belongsToMany(RoleModel::getClass(), 'permission_role', 'permission_id', 'role')->withPivot('permission_type');
    }

    /* CALCULATED FIELDS */
    public static function findByKey($permissionKey)
    {
        return Permission::where('permission_key', $permissionKey)->first();
    }

    public function getPermissionTypeByRoleId($roleId)
    {
        return $this->roles->firstWhere('id', $roleId)?->pivot?->permission_type;
    }

    // SCOPES 
    public function scopeForSection($query, $sectionId)
    {
        $query->where('permission_section_id', $sectionId);
    }

    public function scopeGetAllPermissionsBySections($query, $sectionId = null)
    {
        return $query->selectRaw('
            CONCAT_WS("|", 
                GROUP_CONCAT(DISTINCT permission_role.permission_type SEPARATOR "|"), 
                CASE 
                    WHEN (' . \DB::table('permission_sections')
                        ->selectRaw('COUNT(permissions.id) != COUNT(permissions2.id)')
                        ->whereColumn('permission_sections.id', 'permissions.permission_section_id')
                        ->leftJoin('permissions as permissions2', 'permission_sections.id', '=', 'permissions2.permission_section_id')
                        ->groupBy('permission_sections.id')
                        ->limit(1)
                        ->toRawSql() . ') 
                    THEN "0" 
                    ELSE NULL
                END
            ) as permission_type, 
            permission_section_id, 
            COUNT(permissions.id) as role_permissions_count'
        )
        ->when($sectionId, fn($q) => $q->where('permission_section_id', $sectionId))
        ->groupBy('permission_section_id', 'permission_role.role');
    }

    /* ACTIONS */

    /* ELEMENTS */
}
