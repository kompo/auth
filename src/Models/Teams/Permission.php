<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Facades\RoleModel;
use Condoedge\Utils\Models\Model;
use Kompo\Auth\Facades\UserModel;
use Kompo\Auth\Teams\Cache\PermissionCacheInvalidator;
use Kompo\Auth\Teams\Cache\PermissionDefinitionCache;
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

    public static function booted()
    {
        parent::booted();

        static::saved(function ($permission) {
            $permission->clearCache();
        });

        static::deleted(function ($permission) {
            $permission->clearCache();
        });
    }
    
    /* RELATIONS */
    public function roles()
    {
        return $this->belongsToMany(RoleModel::getClass(), 'permission_role', 'permission_id', 'role')->withPivot('permission_type');
    }

    /* CALCULATED FIELDS */
    public static function findByKey($permissionKey)
    {
        return app(PermissionDefinitionCache::class)->permissionByKey($permissionKey);
    }

    protected function clearCache(): void
    {
        $permissionKeys = array_values(array_filter(array_unique([
            $this->permission_key,
            $this->getOriginal('permission_key'),
        ])));

        $sectionIds = array_values(array_filter(array_unique([
            $this->permission_section_id,
            $this->getOriginal('permission_section_id'),
        ])));

        app(PermissionCacheInvalidator::class)->permissionChanged($this, $permissionKeys, $sectionIds);
    }

    public function getPermissionTypeByRoleId($roleId)
    {
        return $this->roles->firstWhere('id', $roleId)?->pivot?->permission_type;
    }

    /**
     * Get users who have this permission, considering role_hierarchy
     * (DIRECT / BELOW / NEIGHBOURS) and DENY precedence.
     *
     * @param iterable|int|null $teamsIds optional team IDs to scope the check;
     *                                    null = any team the user has access to.
     * @param PermissionTypeEnum $type required permission level.
     * @return \Illuminate\Support\Collection user models.
     */
    public function getUsersWithPermission(
        $teamsIds = null,
        PermissionTypeEnum $type = PermissionTypeEnum::ALL
    ): \Illuminate\Support\Collection {
        $query = app(\Kompo\Auth\Teams\Contracts\PermissionResolverInterface::class)
            ->getUsersQueryWithPermission(
                $this->permission_key,
                $type,
                $teamsIds,
                UserModel::getTable()
            );

        return UserModel::query()
            ->whereIn(UserModel::qualifyColumn(UserModel::getKeyName()), $query)
            ->get();
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
