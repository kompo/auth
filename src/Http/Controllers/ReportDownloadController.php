<?php

namespace Kompo\Auth\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class ReportDownloadController extends Controller
{
    public function __invoke($filename)
    {
        return Storage::download($filename, static::downloadName(currentTeam()?->team_name, $filename));
    }

    /**
     * Keeps accents and apostrophes — Laravel signs an ASCII fallback and Symfony adds
     * filename*=UTF-8''. Only what would throw in Content-Disposition or break a saved file goes.
     */
    public static function downloadName($teamName, $filename): string
    {
        // No /u: preg_replace returns null on invalid UTF-8, which would blank the whole name.
        $strip = '/[\/\\\\"\x00-\x1F\x7F]+/';
        $team = trim(preg_replace($strip, '', (string) $teamName));

        return preg_replace($strip, '', ($team === '' ? '' : $team . ' - ') . $filename);
    }
}
