<?php

namespace Kompo\Auth\Notes;

use Kompo\Auth\Common\Modal;
use Kompo\Auth\Models\Notes\Note;

class NoteForm extends Modal
{
    public $_Title = 'notes.add-note';
    public $model = Note::class;

    protected $noHeaderButtons = true;

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
            _Input('notes.content')->name('content_nt'),
            _DateTime('notes.datetime')->name('date_nt')->default(now()),

            _FlexBetween(
                !$this->model->id ? null : 
                    _DeleteButton('translate.notes.delete')->class('flex-1')->byKey($this->model)->closeModal()->refresh(NotesList::ID),
                _SubmitButton('notes.save')->class('flex-1')->closeModal()->refresh(NotesList::ID),
            )->class('gap-4')
        );
    }
}