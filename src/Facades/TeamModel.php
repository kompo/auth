<?php

namespace Kompo\Auth\Facades;

use Illuminate\Support\Facades\Facade;

class TeamModel extends Facade
{
    public static function getFacadeAccessor()
    {
        return 'team-model';
    }
}