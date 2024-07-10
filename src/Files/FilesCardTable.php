<?php

namespace Kompo\Auth\Files;

use Kompo\Auth\Models\Files\File;
use Kompo\Query;

class FilesCardTable extends Query
{
    protected $teamId;
    protected $fileableId;
    protected $fileableType;

    protected $fileable;

    public function created()
    {
        $this->teamId = currentTeamId();
        $this->fileableId = $this->prop('fileable_id');
        $this->fileableType = $this->prop('fileable_type');

		$this->perPage = $this->prop('limit') ?? 10;
		$this->hasPagination = $this->prop('has_pagination');

        $this->fileable = findOrFailMorphModel($this->fileableId, $this->fileableType);
    }

	public function query()
	{
		return File::where('team_id', $this->teamId)
			->where('fileable_type', $this->fileableType)
			->where('fileable_id', $this->fileableId)
			->orderByDesc('created_at');
	}

	public function top()
	{
		return _FlexBetween(
            _TitleCard('files-my-files'),
            _CreateCard()->selfCreate('getFileForm')->inModal(),
        )->class('mb-4');
	}

	public function render($file)
	{
		return _FlexBetween(
			_Flex(
				$file->thumb?->class('mr-2 shrink-0'),
				_Rows(
					_Html($file->name),
					_Html($file->created_at->diffForHumans() . ' - ' . sizeAsKb($file->size))->class('text-sm text-gray-400'),
				),
			)->class('gap-4'),
        	_Delete($file),
       )->class('py-3')->selfUpdate('getFileForm', ['id' => $file->id])->inModal();
	}

    public function getFileForm($id = null)
    {
        return new FileForm($id, [
        	'fileable_id' => $this->fileableId,
        	'fileable_type' => $this->fileableType,
        ]);
    }
}
