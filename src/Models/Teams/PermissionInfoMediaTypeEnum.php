<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Models\Traits\EnumKompo;

/**
 * Media kind for a single carousel slide on a permission's info modal.
 *
 *   IMAGE  — an uploaded image or animated gif (stored as a file array).
 *   SCRIBE — a scribehow.com guide, embedded through an iframe by its id.
 */
enum PermissionInfoMediaTypeEnum: int
{
    use EnumKompo;

    case IMAGE = 1;
    case SCRIBE = 2;

    public function label(): string
    {
        return match ($this) {
            self::IMAGE => __('auth-permission-media-image'),
            self::SCRIBE => __('auth-permission-media-scribe'),
        };
    }

    public function formInput()
    {
        return match( $this) {
            self::IMAGE => _Image('auth-permission-image')->name('image'),
            self::SCRIBE => _Input('auth-permission-scribe-id')->name('scribe_id'),
        };
    }
}
