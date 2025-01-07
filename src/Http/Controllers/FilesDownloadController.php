<?php

namespace Kompo\Auth\Http\Controllers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class FilesDownloadController extends Controller
{
    public function __invoke($type, $id)
    {
    	$model = Relation::morphMap()[$type];

    	$model = $model::findOrFail($id);

        $disk = $model->disk ?? 'local';

        if (!auth()->user()->can('view', $model)) {
            abort(403, __('error.you-cant-download-this-file'));
        }

        if (!Storage::disk($disk)->exists($model->path)) {
            abort(404, __('error.file-not-found'));
        }

    	return Storage::disk($disk)->download($model->storagePath(), $model->name);
    }
}
