<?php

namespace Kompo\Auth\Facades;

use Kompo\Komponents\Form\KompoModelFacade;

/**
 * @mixin \Kompo\Auth\Models\User
 */
class UserModel extends KompoModelFacade
{
    protected static function getModelBindKey()
    {
        return USER_MODEL_KEY;
    }
}