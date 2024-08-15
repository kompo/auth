<?php

namespace Kompo\Auth\Facades;

use Kompo\Komponents\Form\KompoModelFacade;

/**
 * @mixin \Kompo\Auth\Models\Files\File
 */
class FileModel extends KompoModelFacade
{
    protected static function getModelBindKey()
    {
        return FILE_MODEL_KEY;
    }
}