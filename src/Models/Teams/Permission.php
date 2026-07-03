<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Contracts\Security\OptsOutOfSecurity;
use Kompo\Auth\Facades\RoleModel;
use Condoedge\Utils\Models\Model;
use Kompo\Auth\Facades\UserModel;
use Kompo\Auth\Teams\Cache\PermissionCacheInvalidator;
use Kompo\Auth\Teams\Cache\PermissionDefinitionCache;
use Kompo\Database\HasTranslations;

class Permission extends Model implements OptsOutOfSecurity
{
    use HasTranslations;

    protected $fillable = [
        'permission_key',
        'permission_name',
        'permission_description_read',
        'permission_description_write',
        'permission_section_id',
        'object_type',
        'supported_types',
        'added_by',
        'modified_by'
    ];

    protected $translatable = [
        'permission_name',
        'permission_description_read',
        'permission_description_write',
    ];

    protected $casts = [
        'object_type' => PermissionObjectTypeEnum::class,
        'supported_types' => 'integer',
    ];

    /**
     * Whether this permission accepts the given type. Bitmask AND;
     * `DENY` is always accepted (separate axis).
     */
    public function supportsType(PermissionTypeEnum $type): bool
    {
        return $type->isSupportedBy((int) ($this->supported_types ?? PermissionTypeEnum::ALL->value));
    }

    /** @var list<PermissionTypeEnum>|null */
    protected ?array $cachedSupportedTypes = null;

    /**
     * The list of `PermissionTypeEnum` cases this permission accepts — memoized
     * per instance so per-cell matrix renders don't redecode the bitmask.
     *
     * @return list<PermissionTypeEnum>
     */
    public function supportedTypes(): array
    {
        if ($this->cachedSupportedTypes !== null) {
            return $this->cachedSupportedTypes;
        }

        $supported = (int) ($this->supported_types ?? PermissionTypeEnum::ALL->value);
        return $this->cachedSupportedTypes = collect(PermissionTypeEnum::cases())
            ->filter(fn($case) => $case->isSupportedBy($supported))
            ->values()
            ->all();
    }

    // Permission rows must be readable without going through the auth scope —
    // reading the permission table is part of the auth check itself, so any
    // read restriction here causes infinite recursion.
    public function getSkippedSecurityOperations(): array
    {
        return ['read'];
    }

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

    public function slides()
    {
        return $this->hasMany(PermissionInfoSlide::class)->orderBy('position')->orderBy('id');
    }

    public function dependencies()
    {
        return $this->belongsToMany(static::class, 'permission_dependencies', 'permission_id', 'required_permission_id');
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
