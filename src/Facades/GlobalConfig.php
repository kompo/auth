<?php

namespace Kompo\Auth\Facades;

use Illuminate\Support\Facades\Facade;
use Kompo\Auth\GlobalConfig\GlobalConfigServiceContract;

/**
 * @method static mixed get(string $key, $default = null)
 *
 * @see \App\GlobalConfig\GlobalConfigServiceContract
 */
class GlobalConfig extends Facade
{
    protected static function getFacadeAccessor()
    {
        return GlobalConfigServiceContract::class;
    }
}
