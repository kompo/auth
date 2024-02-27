<?php

namespace Kompo\Auth\Services;

use Illuminate\Support\Facades\Route;
use Kompo\Auth\Files\FileLibraryAttachmentQuery;
use Kompo\Auth\Files\FilesManagerView;

class FileService 
{
    public static function setAttachmentRoutes()
    {
        Route::get('add-file-as-attachment/{checked_items?}', FileLibraryAttachmentQuery::class)->name('file-add-attachment.modal');
    }

    public static function setUploadManagerRoutes()
    {
        Route::get('files-manager', FilesManagerView::class)->name('files-manager');
    }

    public static function setAllRoutes()
    {
        self::setAttachmentRoutes();
        self::setUploadManagerRoutes();
    }
}