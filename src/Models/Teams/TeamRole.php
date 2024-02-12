<?php

namespace Kompo\Auth\Models\Teams;
use App\Models\User;
use Kompo\Auth\Models\Model;
use Kompo\Auth\Models\Teams\BaseRoles\SuperAdminRole;
use Kompo\Auth\Models\Teams\BaseRoles\TeamOwnerRole;


class TeamRole extends Model
{    
    protected $table = 'team_user';

    /**
     * Set the roles of your application here.
     *
     * @var string
     */
    public const ROLE_SUPERADMIN = 'super-admin'; //Can only be set manually 
    public const ROLE_OWNER = 'owner';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_EMPLOYEE = 'employee';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_CUSTOMER = 'customer';

    /* CALCULATED FIELDS */
    public static function getUsableRoleClasses()
    {
        $appRolesDir = app_path('Models/Roles');

        $allRoles = collect([
            //SuperAdminRole::class,
            TeamOwnerRole::class,
        ]);

        if (is_dir($appRolesDir)) {

            $appRoles = collect(\File::allFiles($appRolesDir))
                ->map(fn($file) => 'App\\Models\\Roles\\'.$file->getFilenameWithoutExtension());

            $allRoles = $allRoles->concat($appRoles);
        }

        return $allRoles;
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
        return _ButtonGroup('Role')->name('role')->selectedClass('bg-dom-300 font-medium', '')
            ->options(
                static::buttonOptions()
            )->vertical();
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
