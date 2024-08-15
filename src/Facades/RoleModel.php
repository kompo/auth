<?php

namespace Kompo\Auth\Facades;

use Kompo\Komponents\Form\KompoModelFacade;

/**
 * @mixin \Kompo\Auth\Models\Teams\Roles\Role;
 */
class RoleModel extends KompoModelFacade
{
    protected static function getModelBindKey()
    {
        return 'role-model';
    }
}