<?php

namespace Kompo\Auth\Http\Controllers;

use Illuminate\Routing\Controller;
use Kompo\Auth\Models\ShortUrl;

class ShortUrlController extends Controller
{
    public function __invoke()
    {
        $code = request('short_url_code');

        $shortUrl = ShortUrl::where('short_url_code', $code)->firstOrFail();

        return redirect($shortUrl->constructRoute());
    }
}
