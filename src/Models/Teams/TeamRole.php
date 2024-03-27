<?php

namespace Kompo\Auth\Models\Teams;
use App\Models\User;
use Kompo\Auth\Models\Model;
use Kompo\Auth\Models\Teams\BaseRoles\SuperAdminRole;
use Kompo\Auth\Models\Teams\BaseRoles\TeamOwnerRole;
use Kompo\Auth\Models\Teams\Permission;

class TeamRole extends Model
{
    use \Kompo\Auth\Models\Teams\BelongsToTeamTrait;
    use \Kompo\Auth\Models\Traits\BelongsToUserTrait;

    public const ROLES_DELIMITER = ',';

    protected $casts = [
        'role_hierarchy' => RoleHierarchyEnum::class,
    ];

    /* RELATIONS */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    /* SCOPES */
    public function scopeRelatedToTeam($query, $teamId = null)
    {
        $query->when($teamId, fn($q) => $q->where('team_id', $teamId));
    }

    /* CALCULATED FIELDS */
    public function getRoleName()
    {
        $roleClass = $this->getRelatedRoleClass();

        return $roleClass::ROLE_NAME;
    }

    public function getRelatedRoleClass()
    {
        return static::getAllRoleClasses()->first(fn($class) => $this->role == $class::ROLE_KEY);
    }

    public static function getUsableRoleClasses()
    {
        $appRolesDir = app_path('Models/Roles');

        $allRoles = collect([
            isSuperAdmin() ? SuperAdminRole::class : null,
            //TeamOwnerRole::class, //there can be only one team owner
        ])->filter();

        if (is_dir($appRolesDir)) {

            $appRoles = collect(\File::allFiles($appRolesDir))
                ->map(fn($file) => 'App\\Models\\Roles\\'.$file->getFilenameWithoutExtension());

            $allRoles = $allRoles->concat($appRoles);
        }

        return $allRoles;
    }

    public static function getAllRoleClasses()
    {
        return static::getUsableRoleClasses()->push(TeamOwnerRole::class);
    }

    public static function baseRoles()
    {
        return [
            SuperAdminRole::class,
            TeamOwnerRole::class,
        ];
    }

    public static function teamRoleRules()
    {
        if (!config('kompo-auth.team_hierarchy_roles')) {
            $rules['role'] = ['required', 'array', 'in:'.implode(',', TeamRole::getUsableRoleClasses()->map(fn($c) => $c::ROLE_KEY)->toArray())];
        } else {
            $rules['multi_roles'] = ['required'];
        }

        return $rules;
    }

    public function getRoleHierarchyAccessDirect()
    {
        return $this->role_hierarchy->accessGrant();
    }

    public function getRoleHierarchyAccessBelow()
    {
        return $this->role_hierarchy->accessGrantBelow();
    }

    /* ACTIONS */

    /* ELEMENTS */
    public static function buttonGroupField($label = 'Role')
    {
        $selectEl = config('kompo-auth.multiple_roles_per_team') ? _MultiSelect($label) : _Select($label);

        return $selectEl->name('role')
            ->options(
                static::buttonOptions()
            );
    }

    public static function buttonOptions()
    {
        return static::getUsableRoleClasses()->mapWithKeys(function ($roleClass) {
            return [
                $roleClass::ROLE_KEY => _Rows(
                    _Html($roleClass::ROLE_NAME)->class('text-sm text-gray-600'),
                    _Html($roleClass::ROLE_DESCRIPTION)->class('mt-2 text-xs text-gray-600'),
                )->class('p-4')
            ];
        });
    }

    public static function roleHierarchySelect()
    {
        return _Select('Role hierarchy')->name('role_hierarchy')
            ->options(
                static::roleHierarchyOptions()
            );
    }

    public static function roleHierarchyOptions()
    {
        return RoleHierarchyEnum::optionsWithLabels();
    }
}
