<?php

namespace Kompo\Auth\Models\Teams;

use Condoedge\Utils\Models\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Kompo\Database\HasTranslations;

/**
 * One page of a permission's info carousel. A slide carries a single media
 * (an uploaded image/gif, or a scribehow.com embed) plus a translatable
 * caption explaining what the permission lets the user do.
 *
 * @property int                          $id
 * @property int                          $permission_id   Foreign key to permissions
 * @property int                          $position        Display order inside the carousel
 * @property PermissionInfoMediaTypeEnum  $media_type
 * @property array|null                   $image           Uploaded file metadata ({path, name, ...})
 * @property string|null                  $scribe_id       scribehow.com guide id (embed)
 * @property array|null                   $caption         Translatable per-page text
 */
class PermissionInfoSlide extends Model
{
    use HasTranslations;

    protected $table = 'permission_info_slides';

    protected $fillable = [
        'permission_id',
        'position',
        'media_type',
        'image',
        'scribe_id',
        'caption',
    ];

    protected $translatable = [
        'caption',
    ];

    protected $casts = [
        'media_type' => PermissionInfoMediaTypeEnum::class,
        'image' => 'array',
    ];

    /* RELATIONS */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /* SCOPES */
    public function scopeOrdered($query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    /* CALCULATED FIELDS */

    /** Public URL of the uploaded image/gif, or null when the slide is a scribe. */
    public function mediaUrl(): ?string
    {
        $path = $this->image['path'] ?? null;

        // Files are uploaded to the disk recorded in the `image` array (e.g. s3),
        // not necessarily the default disk — resolve the URL on that same disk.
        return $path
            ? Storage::disk($this->image['disk'] ?? 'public')->url($path)
            : null;
    }

    /** scribehow embed URL for this slide, or null when the slide is an image. */
    public function scribeEmbedUrl(): ?string
    {
        if (!$this->scribe_id) {
            return null;
        }

        return 'https://scribehow.com/embed/' . $this->scribe_id . '?as=scrollable&skipIntro=true&removeLogo=true';
    }
}
