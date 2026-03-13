<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Facades\RoleModel;
use Condoedge\Utils\Models\Model;
use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Facades\UserModel;
use Kompo\Auth\Models\User;
use Kompo\Database\HasTranslations;

class Permission extends Model
{
    use HasTranslations;

    protected $fillable = [
        'permission_key',
        'permission_name',
        'permission_description',
        'permission_section_id',
        'object_type',
        'added_by',
        'modified_by'
    ];

    protected $translatable = [
        'permission_name',
        'permission_description',
    ];

    protected $casts = [
        'object_type' => PermissionObjectTypeEnum::class,
    ];

    // It's impossible to set this kind of restriction because we read the permission it would be getting a infinite loop.
    protected $readSecurityRestrictions = false;
    
    /* RELATIONS */
    public function roles()
    {
        return $this->belongsToMany(RoleModel::getClass(), 'permission_role', 'permission_id', 'role')->withPivot('permission_type');
    }

    /* CALCULATED FIELDS */
    public static function findByKey($permissionKey)
    {
        return Cache::remember("permission_{$permissionKey}", 30, function () use ($permissionKey) {
            return self::where('permission_key', $permissionKey)->first() ?? false;
        });
    }

    public function getPermissionTypeByRoleId($roleId)
    {
        return $this->roles->firstWhere('id', $roleId)?->pivot?->permission_type;
    }

    public function getUsersWithPermission($teamsIds = null)
    {
        $roleIds = $this->roles->pluck('id')->toArray();

        return UserModel::whereHas('teamRoles', function ($q) use ($roleIds, $teamsIds) {
            $q->whereIn('role', $roleIds);
            
            if ($teamsIds) {
                $q->whereIn('team_id', $teamsIds);
            }
        })->get();
    }

    // SCOPES 
    public function scopeForSection($query, $sectionId)
    {
        $query->where('permission_section_id', $sectionId);
    }

    public function scopeGetAllPermissionsBySections($query, $sectionId = null)
    {
        $totalPermissionsSubquery = \DB::table('permissions as p2')
            ->selectRaw('COUNT(p2.id)')
            ->whereColumn('p2.permission_section_id', 'permissions.permission_section_id')
            ->whereNull('p2.deleted_at')
            ->toRawSql();

        return $query->selectRaw('
            CONCAT_WS("|",
                GROUP_CONCAT(DISTINCT permission_role.permission_type SEPARATOR "|"),
                CASE
                    WHEN COUNT(DISTINCT permissions.id) < (' . $totalPermissionsSubquery . ')
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
