<?php

namespace Kompo\Auth\Files;

use Kompo\Auth\Models\Files\File;
use Kompo\Table;

class FilesManagerView extends Table
{
	public $containerClass = 'container-fluid';

	public $id = 'file-manager-view';

    public $paginationType = 'Scroll';
    public $itemsWrapperClass = 'overflow-y-auto mini-scroll bg-white rounded-2xl p-4';
    public $itemsWrapperStyle = 'max-height: calc(100vh - 150px)';

    public $tableClass = 'table-fixed vlTableNoBorder';

	public function query()
	{
        $search = request('name');
        $type = request('parent_type_bis');
        $year = request('year');
        $month = request('month');

		return File::getLibrary([
            'filename' => $search,
            'fileable_type' => $type,
            'year' => $year,
            'month' => $month,
        ]);
	}

	public function top()
	{
		return File::fileFilters(
			_TitleMain('ka::files.file-manager')->class('mb-4'),
		);
	}

	public function right()
	{
		return _Rows(
            _Button('ka::files.upload-files')->icon('upload')->class('ml-0 sm:ml-4 mb-4')
            ->selfUpdate('getFileUploadModal')
            ->inModal(),
        	_Panel(
    			_TitleCard('ka::files.file-infos'),
            	File::emptyPanel(),
            )->id('file-info-panel')
            ->closable()
            ->class('dashboard-card p-4 mb-4 ml-0 sm:ml-4'), //width managed in CSS
        	_Rows(

	        )->id('recent-files-div')
	        ->class('dashboard-card p-4 mb-4 ml-0 sm:ml-4 w-1/4vw'),
		)->class('ml-0');
	}

	public function headers()
	{
		return [
			_Th()->class('w-20'),
			_Th('ka::general.name')->sort('name')->class('w-60'),
            _Th('ka::general.type')->sort('type')->class('w-20'),
			_Th('ka::general.date')->sort('created_at')->class('w-20'),
            _Th('ka::general.actions')->sort('updated_at')->class('w-10'),
		];
	}

	public function render($file)
	{
        // dd(              );
		return _TableRow(
            _Html()->class('w-20'),
            _Html($file->name),
            _Html(ucfirst($file->fileable_type ?: '-')),
			$file->uploadedAt(),
            _Columns(
                _Link()->class('mt-1 -mr-2')->col('col-md-3')
                    ->icon('arrow-down')
                    ->href($file->link)
                    ->attr(['download' => $file->name]),
                _Delete($file)->col('col-md-3'),
            )->class('px-2'),
		)->class('text-sm cursor-pointer')
		->selfGet('getFileInfo', [
			'id' => $file->id,
		])->inPanel('file-info-panel');
	}

    public function getFileInfo()
    {
        $id = request('id');

    	return new FileInfo($id);
    }

	public function getYearsMonthsFilter()
	{
		return File::yearlyMonthlyLinkGroup();
	}

	public function getFileUploadModal()
	{
		return new FileUploadModalManager();
	}
}
