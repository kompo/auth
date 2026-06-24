<?php

namespace Kompo\Auth\Models\Teams;

/**
 * Media kind for a single carousel slide on a permission's info modal.
 *
 *   IMAGE  — an uploaded image or animated gif (stored as a file array).
 *   SCRIBE — a scribehow.com guide, embedded through an iframe by its id.
 */
enum PermissionInfoMediaTypeEnum: int
{
    case IMAGE = 1;
    case SCRIBE = 2;

    public function label(): string
    {
        return match ($this) {
            self::IMAGE => __('auth-permission-media-image'),
            self::SCRIBE => __('auth-permission-media-scribe'),
        };
    }

    /** Whether this media kind is fed by an uploaded file rather than an external id. */
    public function usesUpload(): bool
    {
        return match ($this) {
            self::IMAGE => true,
            self::SCRIBE => false,
        };
    }

    /** @return array<int, string> value => label, for selects. */
    public static function optionsWithLabels(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
