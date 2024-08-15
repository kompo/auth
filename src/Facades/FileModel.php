<?php

namespace Kompo\Auth\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @mixin \Kompo\Auth\Models\Files\File
 */
class FileModel extends Facade
{
    use FacadeUtils;
    
    protected static function getFacadeAccessor()
    {
        return FILE_MODEL_KEY;
    }
}