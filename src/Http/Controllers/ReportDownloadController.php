<?php

namespace Kompo\Auth\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class ReportDownloadController extends Controller
{
    public function __invoke($filename)
    {
    	return Storage::download($filename, preg_replace('/[^A-Za-z0-9\-]/', '', currentTeam()->name).' - '.$filename);
    }
}
