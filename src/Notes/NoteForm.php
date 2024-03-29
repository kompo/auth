<?php

namespace Kompo\Auth\Notes;

use Kompo\Auth\Common\Modal;
use Kompo\Auth\Models\Notes\Note;

class NoteForm extends Modal
{
    public $_Title = 'ka::notes.add-note';
    public $model = Note::class;

    protected $notableType = \App\Models\User::class;
    protected $notableId;
    
    public function created()
    {
        $this->notableType = $this->prop('notable_type') ?? $this->notableType;
        $this->notableId = $this->prop('notable_id');
    }

    public function beforeSave()
    {
        $this->model->notable_type = $this->notableType;
        $this->model->notable_id = $this->notableId;
    }
    
    public function body()
    {
        return _Rows(
            _Input('ka::notes.content')->name('content_nt'),
            _DateTime('ka::notes.datetime')->name('date_nt'),
        );
    }

    public function headerButtons()
    {
        return $this->noHeaderButtons ? null : _SubmitButton('ka::notes.save')->closeModal()->refresh(NotesList::ID);
    }
}