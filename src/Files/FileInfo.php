<?php

namespace Kompo\Auth\Files;

use Kompo\Auth\Models\Files\File;
use Kompo\Form;

class FileInfo extends Form
{
    public $model = File::class;

    public function render()
    {
        return _CardWhite(
            _Html(__('translate.files.file-name', ['name' => $this->model->name]))->class('text-sm font-bold mb-1'),
            _Html(__('translate.files.file-size', ['size' => $this->model->size]))->class('text-sm'),
            _Flex(
                _Html(__('translate.files.file-uploaded-at'))->class('text-sm'),
                $this->model->uploadedAt()->class('!text-sm !text-black'),
            )->class('gap-2'),
            $this->fileableInfo(),
            _Button('translate.files.edit-form')
                ->selfGet('getFileForm', ['id' => $this->model->id])->inModal()
                ->class('mt-4'),
            // _Html(__('file.file-uploaded-by') . ': ' . $file->uploadedBy())->class('text-sm'),
        )->class('pr-12 pl-6 py-4 min-w-[25%]');
    }

    public function getFileForm($id)
    {
        return new FileUploadModalManager($id);
    }

    protected function fileableInfo()
    {
        if(!$this->model->fileable) return null;

        return _Rows(
            _Html(__('translate.files.fileable-type', ['type' => $this->model->fileable_type]))->class('text-sm'),
            _Html(__('translate.files.fileable-id', ['id' => $this->model->fileable->id]))->class('text-sm'),
        );
    }
}
