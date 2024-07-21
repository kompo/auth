<?php

namespace Kompo\Auth\Notes;

use Kompo\Auth\Facades\NoteModel;
use Kompo\Query;

class NotesList extends Query
{
    protected $notableType = \App\Models\User::class;
    protected $notableId;

    public const ID = 'notes-list';

    public function created()
    {
        $this->notableType = $this->prop('notable_type') ?? $this->notableType;
        $this->notableId = $this->prop('notable_id');

        $this->perPage = $this->prop('limit') ?? 10;
		$this->hasPagination = $this->prop('has_pagination');

        $this->id(self::ID);
    }

    public function query()
    {
        return NoteModel::forNotableType($this->notableType)
            ->forNotableId($this->notableId)
            ->latest();
    }

    public function top()
    {
        return _FlexBetween(
            _TitleCard('notes.notes'),
            _CreateCard()->selfCreate('getNoteForm')->inModal(),
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
        return _Flex(
            $note->addedBy->getProfilePhotoPill('h-8 w-8') ? : _Sax('message-text', 20),
            _Rows(
                _Html($note->content_nt),
                _Html($note->date_nt?->diffForHumans() . ' - ' . $note->addedBy->name)->class('text-sm text-geenmain opacity-50'),
            ),
        )->class('gap-4 py-3')->selfGet('getNoteForm', ['id' => $note->id])->inModal();
    }

    public function getNoteForm($id = null)
    {
        return new NoteForm($id, [
            'notable_type' => $this->notableType,
            'notable_id' => $this->notableId,
        ]);
    }
}