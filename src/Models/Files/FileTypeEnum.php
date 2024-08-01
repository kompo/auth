<?php

namespace Kompo\Auth\Models\Files;

enum FileTypeEnum: int
{
    use \Kompo\Auth\Models\Traits\EnumKompo;
    
    case IMAGE = 1;
    case PDF = 2;
    case COMPRESSED = 3;
    case DOCUMENT = 4;
    case SPREADSHEET = 5;
    case AUDIO = 6;
    case VIDEO = 7;

    case UNKNOWN = 8;

    public function label()
    {
        return match ($this) {
            self::IMAGE => 'file-type-image',
            self::PDF => 'file-type-pdf',
            self::COMPRESSED => 'file-type-compressed',
            self::DOCUMENT => 'file-type-document',
            self::SPREADSHEET => 'file-type-spreadsheet',
            self::AUDIO => 'file-type-audio',
            self::VIDEO => 'file-type-video',
            default => 'file-type-unknown',
        };
    }

    public function mimeTypes()
    {
        return match ($this) {
            self::IMAGE => ['image/jpeg', 'image/gif', 'image/png', 'image/bmp', 'image/svg+xml', 'image/webp'],
            self::PDF => ['application/pdf'],
            self::COMPRESSED => ['application/x-rar-compressed', 'application/zip', 'application/x-gzip', 'application/gzip', 'application/vnd.rar', 'application/x-7z-compressed'],
            self::DOCUMENT => ['text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            self::SPREADSHEET => ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            self::AUDIO => ['audio/basic', 'audio/aiff', 'audio/mpeg', 'audio/midi', 'audio/wave', 'audio/ogg'],
            self::VIDEO => ['video/avi', 'video/x-msvideo', 'video/mpeg', 'video/ogg', 'video/x-matroska'],
            default => [],
        };
    }

    public function icon()
    {
        return match ($this) {
            self::IMAGE => 'coolecto-image',
            self::PDF => 'coolecto-pdf',
            self::COMPRESSED => 'coolecto-archive',
            self::DOCUMENT => 'coolecto-word',
            self::SPREADSHEET => 'coolecto-excel',
            self::AUDIO => 'coolecto-audio',
            self::VIDEO => 'coolecto-video',
            default => 'coolecto-archive',
        };
    }

    public function isPreviewable()
    {
        return in_array($this, [self::IMAGE, self::PDF, self::AUDIO, self::VIDEO]);
    }

    public function getPreviewButton($komponent, $model)
    {
        return match ($this) {
            self::IMAGE => $komponent->get('image.preview', ['id' => $model->id, 'type' => $model->getMorphClass()])->inModal(),
            self::PDF => $komponent->href(fileRoute($model->getMorphClass(), $model->id))->inNewTab(),
            self::AUDIO => $komponent->get('audio.preview', ['id' => $model->id, 'type' => $model->getMorphClass()])->inModal(),
            self::VIDEO => $komponent->get('video.preview', ['id' => $model->id, 'type' => $model->getMorphClass()])->inModal(),
            default => null,
        };
    }

    public static function fromMimeType($mimeType)
    {
        foreach (self::cases() as $case) {
            if (in_array($mimeType, $case->mimeTypes())) {
                return $case;
            }
        }

        return self::UNKNOWN;
    }
}