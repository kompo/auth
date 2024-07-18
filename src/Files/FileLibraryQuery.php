<?php

namespace Kompo\Auth\Files;

use Kompo\Auth\Facades\FileModel;
use Kompo\Query;

class FileLibraryQuery extends Query
{
	public $layout = 'Grid';

	public $class = 'max-w-4xl';

    public $paginationType = 'Scroll';
    public $itemsWrapperClass = 'pt-2 overflow-y-auto mini-scroll container';
    public $itemsWrapperStyle = 'max-height: 300px';

	protected $parentId;
	protected $parentType;

	protected $browseCard;

	public function created()
	{
		$this->parentId = $this->store('parent_id');
		$this->parentType = $this->store('parent_type');

		$this->browseCard = $this->store('browse_card');
	}

	public function query()
	{
		return FileModel::getLibrary();
	}

	public function top()
	{
		return FileModel::fileFilters(
			_MiniTitle('files-link-from-library'),
		);
	}

	public function render($file)
	{
		return $file->linkEl()
            ->selfPost('linkFileTo', ['id' => $file->id])
            ->removeSelf()
            ->alert('files-file-linked')
			->browse($this->browseCard);
	}

	public function linkFileTo($id)
	{
		$file = FileModel::findOrFail($id);

		$file->linkToOrAssociate($this->parentId, $this->parentType);
	}

	public function getTagsMultiSelect($withoutIds = [])
	{
		return FileModel::conditionalTagsMultiselect($withoutIds);
	}

	public function getYearsMonthsFilter()
	{
		return FileModel::yearlyMonthlyLinkGroup();
	}
}
