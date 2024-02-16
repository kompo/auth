<?php

namespace App\Kompo\Library;

use App\Kompo\Common\Modal;
use App\Models\Library\File;

class FileUploadModalManager extends Modal
{
    public $model = File::class;

	public $class = 'overflow-y-auto mini-scroll';
	public $style = 'max-height:95vh; min-width: 350px;';

	protected $_Title = 'file.upload-one-multiple-files';
	protected $_Icon = 'document-text';

    protected $noHeaderButtons = true;

	public function handle()
    {
        if(!$this->model->id) {
            File::uploadMultipleFiles(request()->file('files'), $this->model->fileable_type, $this->model->fileable_id, request('tags'));
        } else {
            $this->model->fileable_type = request('fileable_type');
            $this->model->fileable_id = request('fileable_id');
            if(request('tags')) $this->model->tags()->sync(request('tags'));
            $this->model->save();
        }
	}

	public function body()
	{
		return _Rows(
            $this->model->id ? null : _MultiFile()->name('files')->placeholder('file.upload-one-multiple-files')
                ->class('text-gray-600 large-file-upload mb-10'),
            _Rows(
                _TagsMultiSelect()->class('mb-10'),
            ),
            _Rows(
                _Html('file.fileable')->class('text-lg font-semibold mb-2'),
                new FileFileableForm($this->model->id),
            ),
            _SubmitButton()->closeModal(),
        );
	}

	public function rules()
	{
        if($this->model->id) {
            return [];
        }

		return [
			'files' => 'required',
        ];
	}
}
