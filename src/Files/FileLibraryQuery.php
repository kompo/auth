<?php

namespace Kompo\Auth\Files;

use Kompo\Auth\Models\Files\File;
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
		return File::getLibrary();
	}

	public function top()
	{
		return File::fileFilters(
			_MiniTitle('file.link-from-library'),
		);
	}

	public function render($file)
	{
		return $file->fileThumbnail('link-to')
            ->selfPost('linkFileTo', ['id' => $file->id])
            ->removeSelf()
            ->alert('file.file-linked')
			->browse($this->browseCard);
	}

	public function linkFileTo($id)
	{
		$file = File::findOrFail($id);

		$file->linkToOrAssociate($this->parentId, $this->parentType);
	}

	public function getTagsMultiSelect($withoutIds = [])
	{
		return File::conditionalTagsMultiselect($withoutIds);
	}

	public function getYearsMonthsFilter()
	{
		return File::yearlyMonthlyLinkGroup();
	}
}
