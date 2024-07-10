<?php

namespace Kompo\Auth\Http\Controllers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class FilesDisplayController extends Controller
{
    public function __invoke($type, $id)
    {
    	$model = Relation::morphMap()[$type];

    	$model = $model::findOrFail($id);

        // if (!auth()->user()->can('view', $model)) {
        //     abort(403, __('error.you-cant-view-this-file'));
        // }
        
        if (!Storage::disk('public')->exists($model->path)) {
            abort(404, __('error.file-not-found'));
        }

        $file = Storage::disk('public')->get($model->path);
        $type = Storage::disk('public')->mimeType($model->path);

        $response = \Response::make($file, 200);
        $response->header("Content-Type", $type);

        return $response;
    }
}
