<?php

namespace Kompo\Auth\Notes;

use Kompo\Auth\Models\Notes\Note;
use Kompo\Table;

class NotesList extends Table
{
    protected $notableType = \App\Models\User::class;
    protected $notableId;

    public function created()
    {
        $this->notableType =  request('notable_type') ?? $this->notableType;
        $this->notableId = request('notable_id');
    }

    public function query()
    {
        return Note::forNotableType($this->notableType)
            ->forNotableId($this->notableId)
            ->latest();
    }

    public function headers()
    {
        return [
            _Th('Note'),
            _Th('Created By'),
            _Th('Created At'),
        ];
    }

    public function render($note)
    {
        return _TableRow(
            _Html($note->content_nt),
            _Html($note->added_by->name),
            _Html($note->date_nt->format('Y-m-d H:i')),
        )->selfGet('note', ['id' => $note->id]);
    }

    public function getNoteForm($id)
    {
        return new NoteForm($id);
    }
}