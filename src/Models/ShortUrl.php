<?php

namespace Kompo\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class ShortUrl extends Model
{
    protected $casts = [
        'params' => 'object',
    ];

    /* RELATIONSHIPS */


    /* ATTRIBUTES */


    /* CALCULATED FIELDS */
    public function getLinkUrl()
    {
        return route('short-url', [
            'short_url_code' => $this->short_url_code,
        ]);
    }

    /* SCOPES */


    /* ACTIONS */
    public static function createNewShortLink($route, $params, $isSigned = 0, $uniqueIdentifier = null)
    {
        $shortUrl = static::createAndGetModel($route, $params, $isSigned, $uniqueIdentifier);

        return $shortUrl->getLinkUrl();
    }

    public static function createAndGetModel($route, $params, $isSigned = 0, $uniqueIdentifier = null)
    {
        if ($uniqueIdentifier && $shortUrl = ShortUrl::where('unique_identifier', $uniqueIdentifier)->first()) {
            return $shortUrl;
        }

        $shortUrl = new ShortUrl();

        $shortUrl->short_url_code = getRandStringForModel(new static, 'short_url_code');
        $shortUrl->route = $route;
        $shortUrl->is_signed = $isSigned;
        $shortUrl->unique_identifier = $uniqueIdentifier;
        $shortUrl->params = $params;

        $shortUrl->save();

        return $shortUrl;
    }

    public static function extractFromUrl($url)
    {
        $shortUrlCode = explode('/', $url);
        $shortUrlCode = end($shortUrlCode);

        return static::where('short_url_code', $shortUrlCode)->first();
    }

    public function getQr($size = 200)
    {
        $path = 'qr-codes/short-link-'.$this->id.'.png';
        $disk = config('kompo.default_storage_disk.image');

        if (!Storage::disk($disk)->exists($path)) {
            $qrCode = QrCode::format('png')->size($size)->generate($this->getLinkUrl());

            Storage::disk($disk)->put('qr-codes/short-link-'.$this->id.'.png', $qrCode);
        }

        return Storage::url($path);
    }

    public function constructRoute()
    {
        $parsedParams = objectToArray($this->params);

        if ($this->is_signed) {
            return \URL::signedRoute($this->route, $parsedParams);
        }

        return route($this->route, $parsedParams);
    }
}
