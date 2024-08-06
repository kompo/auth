<?php

namespace Kompo\Auth\Models\Teams\Roles;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use App\Models\Teams\TeamLevelEnum;

class RoleTeamLevel extends EloquentModel
{
    protected $table = 'role_team_levels';

    protected $casts = [
        'team_level' => TeamLevelEnum::class,
    ];

    protected $fillable = [
        'role', 'team_level'
    ];

    public function role()
    {
        return $this->belongsTo(Role::class, 'role');
    }    
}