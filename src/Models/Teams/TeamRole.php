<?php

namespace Kompo\Auth\Models\Teams;
use Kompo\Auth\Models\Model;
use App\Models\User;


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
    public static function roles()
    {
        return [
            static::ROLE_OWNER => 'Team owner',
            static::ROLE_MANAGER => 'Manager',
            static::ROLE_EMPLOYEE => 'Employee',
            static::ROLE_ASSISTANT => 'Assitant',
            static::ROLE_CUSTOMER => 'Customer',
        ];
    }

    public static function descriptions()
    {
        return [
            static::ROLE_OWNER => 'Owner users can perform any action.',
            static::ROLE_MANAGER => 'Manager users have the editor\'s abilities and managing team members.',
            static::ROLE_EMPLOYEE => 'Employee users have the ability to read, create, and update.',
            static::ROLE_ASSISTANT => 'Assistants have the ability to read, create, and update.',
            static::ROLE_CUSTOMER => 'Customer of the team. They see a different dashboard with only their restricted information.',
        ];
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
    public static function createTeamRole($role, $team, $user)
    {
        $teamRole = new static();
        $teamRole->team_id = $team->id;
        $teamRole->user_id = $user->id;
        $teamRole->role = $role;
        $teamRole->save();
    }

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
        return collect(static::roles())->mapWithKeys(function ($role, $roleKey) {
            return [
                $roleKey => _Rows(
                    _Html(static::roles()[$roleKey])->class('text-sm text-gray-600'),
                    _Html(static::descriptions()[$roleKey])->class('mt-2 text-xs text-gray-600'),
                )->class('p-4')
            ];
        });
    }
}
