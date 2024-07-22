<?php
namespace Kompo\Auth\Facades;

trait FacadeUtils
{
    public static function getClass()
    {
        return self::getFacadeRoot()::class;
    }
}