<?php

namespace Kompo\Auth\Files;

use Kompo\Auth\Models\Files\File;

class FileLibraryAttachmentQuery extends FileLibraryQuery
{
	public $activeClass = 'ring-2 ring-level3 border-level3 rounded-xl';

	public $checkedItemIds;

	public function created()
	{
		$this->checkedItemIds = json_decode($this->parameter('checked_items'), true);

		$this->itemsWrapperClass = $this->itemsWrapperClass.' mx-4 px-6 pb-8';
	}

	public function top()
	{
		return _Rows(
			_FlexBetween(

				_Title('files-attach-from-library')->class('text-2xl sm:text-3xl font-bold')
					->icon('document-text')
					->class('font-semibold mb-4 md:mb-0')
					->class('flex items-center'),

				_FlexEnd(
					_Button('files-confirm')->getElements('confirmSelection')->inPanel('linked-attachments')
						->closeModal()
		                ->config(['withCheckedItemIds' => true])
				)->class('flex-row-reverse md:flex-row md:ml-8')
			)
			->class('bg-gray-50 border-b border-gray-200 px-4 py-6 sm:px-6 rounded-t-xl')
			->class('flex-col items-start md:flex-row md:items-center')
			->alignStart(),
			_Rows(
				parent::top()
			)->class('px-6 py-4'),
		);
	}

	public function render($file)
	{
		return $file->linkEl()->class('mr-4')
			->emit('checkItemId', ['id' => $file->id]);
	}

	public function confirmSelection($selectedIds)
	{
		return static::selectedFiles($selectedIds);
	}

	public static function selectedFiles($selectedIds = [])
	{
		$selectedFiles = File::whereIn('id', $selectedIds ?: [])->get();

		return _Rows(
			!$selectedFiles->count() ? null : _MultiSelect()->name('selected_files', false)
				->options($selectedFiles->mapWithKeys(fn($file) => [$file->id => $file->name]))
				->value($selectedFiles->pluck('id'))
				->class('mb-0'),
			_Button('files-add-from-library')
				->class('text-sm vlBtn vlBtnOutlined')->icon('icon-plus')
				->get('file-add-attachment.modal', [
					'checked_items' => json_encode($selectedIds),
				])->inModal(),
		);
	}

	public static function libraryFilesPanel($selectedIds = [])
	{
		return _Panel(
            static::selectedFiles($selectedIds)
        )->id('linked-attachments');
	}
}
