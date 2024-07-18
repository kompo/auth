<?php

namespace Kompo\Auth\Files;

use Kompo\Auth\Common\Modal;
use Kompo\Auth\Facades\FileModel;

class FileActionsModal extends Modal
{
    protected $_Title = 'translate.files-actions';
    public $model = FileModel::getClass();

    protected $noHeaderButtons = true;

    public function body()
    {
        $previewEl = $this->model->file_type_enum->getPreviewButton(_LinkButton('translate.preview'), $this->model);

        return _FlexBetween(
            _LinkButton('translate.download')->class('flex-1')->col('col-md-3')
                ->icon('arrow-down')
                ->href($this->model->link)
                ->attr(['download' => $this->model->name]),

                $previewEl
        )->class('gap-4');
    }
}