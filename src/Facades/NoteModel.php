<?php

namespace Kompo\Auth\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @mixin \Kompo\Auth\Models\Notes\Note
 */
class NoteModel extends Facade
{
    use FacadeUtils;

    protected static function getFacadeAccessor()
    {
        return 'note-model';
    }
}