<?php

namespace Kompo\Auth\Models\Teams;
use App\Models\User;
use Kompo\Auth\Models\Model;
use Kompo\Auth\Models\Teams\BaseRoles\SuperAdminRole;
use Kompo\Auth\Models\Teams\BaseRoles\TeamOwnerRole;


class TeamRole extends Model
{
    use \Kompo\Auth\Models\Teams\BelongsToTeamTrait;

    protected $table = 'team_user';

    public const ROLES_DELIMITER = ',';

    /* CALCULATED FIELDS */
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

    public static function baseRoles()
    {
        return [
            SuperAdminRole::class,
            TeamOwnerRole::class,
        ];
    }

    public static function usableRoles()
    {
        return static::getUsableRoleClasses()->mapWithKeys(fn($class) => [
            $class::ROLE_KEY => $class::ROLE_NAME,
        ]);
    }

    public static function roleDescriptions()
    {
        return static::getUsableRoleClasses()->mapWithKeys(fn($class) => [
            $class::ROLE_KEY => $class::ROLE_DESCRIPTION,
        ]);
    }

    public static function teamRoleRules()
    {
        return ['required', 'array', 'in:'.implode(',', array_keys(TeamRole::usableRoles()->toArray()))];
    }

    /* RELATIONS */
    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /* ACTIONS */

    /* ELEMENTS */
    public static function buttonGroupField()
    {
        return _MultiSelect('Role')->name('roles')
            ->options(
                static::buttonOptions()
            );
    }

    public static function buttonOptions()
    {
        return collect(static::usableRoles())->mapWithKeys(function ($role, $roleKey) {
            return [
                $roleKey => _Rows(
                    _Html(static::usableRoles()[$roleKey])->class('text-sm text-gray-600'),
                    _Html(static::roleDescriptions()[$roleKey])->class('mt-2 text-xs text-gray-600'),
                )->class('p-4')
            ];
        });
    }
}
