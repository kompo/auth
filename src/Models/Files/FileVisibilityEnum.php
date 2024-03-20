<?php

namespace Kompo\Auth\Models\Files;

enum FileVisibilityEnum: int
{
    case PRIVATE = 0;
    case SEMI = 1;
    case PUBLIC = 2;

    public function option()
    {
        return match ($this) 
        {
            static::PRIVATE => static::visibilityOption('files-private', 'profile-circle'),
            static::SEMI => static::visibilityOption('files-semi-private', 'profile-2user'),
            static::PUBLIC => static::visibilityOption('files-everyone', 'people'),
        };
    }

    protected static function visibilityOption($label, $icon)
    {
        return _Rows(
            _Sax($icon)->class('mx-auto'),
            _Html($label)->class('text-xs'),
        )->class('p-2 text-center');
    }
}