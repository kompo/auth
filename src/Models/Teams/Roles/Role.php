<?php

namespace Kompo\Auth\Models\Teams\Roles;

use Kompo\Auth\Models\Model;
use Kompo\Auth\Models\Teams\Permission;


class Role extends Model
{
    protected $casts = [
        'icon' => 'array',
    ];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'permission_role', 'role', 'permission_id');
    }

    public function getTeamLevels()
    {
        return $this->allowedTeamLevels()->pluck('team_level');
    }

    public function allowedTeamLevels()
    {
        return $this->hasMany(RoleTeamLevel::class, 'role');
    }

    public function assignTeamLevels($teamLevels)
    {
        $this->allowedTeamLevels()->delete();

        $this->allowedTeamLevels()->createMany(
            collect($teamLevels)->map(fn($level) => ['team_level' => $level])
        );
    }
}