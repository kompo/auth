<?php

namespace Kompo\Auth\Services;

use Illuminate\Support\Facades\Route;
use Kompo\Auth\Files\FileLibraryAttachmentQuery;
use Kompo\Auth\Files\FilesManagerView;

class FileService 
{
    public function setAttachmentRoutes()
    {
        Route::get('add-file-as-attachment/{checked_items?}', FileLibraryAttachmentQuery::class)->name('file-add-attachment.modal');
    }

    public function setUploadManagerRoutes()
    {
        Route::get('files-manager', FilesManagerView::class)->name('files-manager');
    }

    public function setAllRoutes()
    {
        $this->setAttachmentRoutes();
        $this->setUploadManagerRoutes();
    }
}