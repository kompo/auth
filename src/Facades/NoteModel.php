<?php

namespace Kompo\Auth\Facades;

use Kompo\Komponents\Form\KompoModelFacade;

/**
 * @mixin \Kompo\Auth\Models\Notes\Note
 */
class NoteModel extends KompoModelFacade
{
    protected static function getModelBindKey()
    {
        return 'note-model';
    }
}