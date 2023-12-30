<?php

namespace Kompo\Auth\Admin;

use Kompo\Table;
use Illuminate\Support\Carbon;

class AdminMailPreviewTable extends Table
{
	public function query()
	{
		$emailsPath = storage_path('email-previews');

		return collect(\File::allFiles($emailsPath))
			->filter(function ($file) {
	            return in_array($file->getExtension(), ['html']);
	        })
			->sortByDesc(function ($file) {
	            return $file->getCTime();
	        });
	}

    public function top()
	{
		return _MedTitleAddButton(
			_Html('campaign.sent-mails')->class('text-level3'),
			_FlexEnd4(

			)
		);
	}

	public function headers()
	{
		return [
			_Th('campaign.email'),
			_Th('campaign.sent-at'),
			_Th(),
			_Th(),
		];
	}

    public function render($file)
    {
        return _TableRow(
        	_Html($file->getBasename())->style('max-width:400px')->class('break-all'),
        	_Html(Carbon::createFromTimestamp($file->getMTime())->translatedFormat('d M Y H:i')),
        	$this->getEmailLink('View .html', $file, 'html'),
        	$this->getEmailLink('Download .eml', $file, 'eml'),
        );
    }

    protected function getEmailLink($label, $file, $extension)
    {
    	return _Link($label)->href(
    		url('spatie-mail-preview').
    			'?mail_preview_file_name='.$file->getBasename('.'.$file->getExtension()).
    			'&file_type='.$extension
    	)->inNewTab();
    }

    protected function getSpatieUrl($fileName, $extension)
    {
    	return url('spatie-mail-preview').'?mail_preview_file_name='.$fileName.'&file_type='.$extension;
    }
}
