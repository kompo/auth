<?php

namespace Kompo\Auth\Facades;

use Illuminate\Support\Facades\Facade;

abstract class FacadeEnum extends Facade
{
    public static function getEnumClass()
    {
        return static::getFacadeRoot();
    }

    public static function __callStatic($method, $args)
    {
        $instance = static::getEnumClass();

        if (! $instance) {
            throw new \RuntimeException('A facade root has not been set.');
        }

        return $instance::$method(...$args);
    }
}