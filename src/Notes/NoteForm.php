<?php

namespace Kompo\Auth\Notes;

use Kompo\Auth\Models\Notes\Note;
use Kompo\Form;

class NoteForm extends Form
{
    public $model = Note::class;
    
    public function render()
    {
        return _Rows(
            _Input('translate.content')->name('content_nt'),
            _DateTime('translate.datetime')->name('date_nt'),
        );
    }
}