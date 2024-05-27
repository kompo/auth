<?php

namespace Kompo\Auth\Notes;

use Kompo\Auth\Models\Notes\Note;
use Kompo\Table;

class NotesList extends Table
{
    protected $notableType = \App\Models\User::class;
    protected $notableId;

    public const ID = 'notes-list';

    public function created()
    {
        $this->notableType = $this->prop('notable_type') ?? $this->notableType;
        $this->notableId = $this->prop('notable_id');

        $this->id(self::ID);
    }

    public function query()
    {
        return Note::forNotableType($this->notableType)
            ->forNotableId($this->notableId)
            ->latest();
    }

    public function top()
    {
        return _FlexBetween(
            _Html('notes.notes')->class('text-2xl font-semibold'),
            _Button('notes.add-note')->selfGet('getNoteForm')->inModal(),
        );
    }

    public function headers()
    {
        return [
            _Th('notes.note'),
            _Th('notes.created-by'),
            _Th('notes.created-at'),
        ];
    }

    public function render($note)
    {
        return _TableRow(
            _Html($note->content_nt),
            _Html($note->addedBy->name),
            _Html($note->date_nt->format('Y-m-d H:i')),
        )->selfGet('getNoteForm', ['id' => $note->id])->inModal();
    }

    public function getNoteForm($id = null)
    {
        return new NoteForm($id, [
            'notable_type' => $this->notableType,
            'notable_id' => $this->notableId,
        ]);
    }
}