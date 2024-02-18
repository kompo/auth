<?php

namespace Kompo\Auth\Files;

use Kompo\Auth\Models\Files\File;
use Kompo\Table;

class FilesCardTable extends Table
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

        $this->fileable = findOrFailMorphModel($this->fileableId, $this->fileableType);
    }

	public function query()
	{
		return File::where('team_id', $this->teamId)
			->where('fileable_type', $this->fileableType)
			->where('fileable_id', $this->fileableId);
	}

	public function top()
	{
		return _FlexBetween(
            _TitleCard('library.my-files'),
            _CreateCard()->selfCreate('getFileForm')->inModal(),
        )->class('mb-4');
	}

	public function headers()
	{
		return [
			_Th('library.type'),
			_Th('library.name'),
			_Th(),
		];
	}

	public function render($file)
	{
		return _TableRow(
			_Html($file->type),
			_Html($file->name),
        	_Delete($file),
       )->selfUpdate('getFileForm', ['id' => $file->id])->inModal();
	}

    public function getFileForm($id = null)
    {
        return new FileForm($id, [
        	'fileable_id' => $this->fileableId,
        	'fileable_type' => $this->fileableType,
        ]);
    }
}
