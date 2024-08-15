<?php

namespace Kompo\Auth\Files;

use Kompo\Auth\Common\Modal;
use Kompo\Auth\Facades\FileModel;

class FileActionsModal extends Modal
{
    protected $_Title = 'auth-files-actions';
    protected $noHeaderButtons = true;
    public $model = FileModel::class;

    public function body()
    {
        $previewEl = $this->model->file_type_enum->getPreviewButton(_LinkButton('auth-preview')->icon('eye'), $this->model);

        return _Rows(
            _Html($this->model->name)->class('text-center font-lg font-medium mb-6 mt-0'),
            _FlexBetween(
                _LinkButton('auth-download')->class('flex-1')->col('col-md-3')
                    ->icon('arrow-down')
                    ->outlined()
                    ->href($this->model->link)
                    ->attr(['download' => $this->model->name]),

                    $previewEl
            )->class('gap-4'),
        )->class('px-2');
    }
}