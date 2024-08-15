<?php

namespace Kompo\Auth\Notes;

use Kompo\Auth\Common\Modal;
use Kompo\Auth\Facades\NoteModel;

class NoteForm extends Modal
{
    public $class = 'max-w-lg';
    public $style = 'width: 98vw';

    public $_Title = 'notes.note';

    protected $noHeaderButtons = true;
    public $model = NoteModel::class;

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
            _Textarea()->name('content_nt')->class('mb-3'),
            _DateTime()->name('date_nt')->default(now())->class('mb-12'),

            _FlexBetween(
                !$this->model->id ? null : 
                    _DeleteButton('notes.delete')->outlined()->class('flex-1')->byKey($this->model)->closeModal()->refresh(NotesList::ID),
                    _SubmitButton('notes.save')->class('flex-1')->closeModal()->refresh(NotesList::ID),
            )->class('gap-3')
        )->class('mx-2 mb-2');
    }
}