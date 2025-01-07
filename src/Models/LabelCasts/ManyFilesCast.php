<?php

namespace Kompo\Auth\Models\LabelCasts;

use Illuminate\Support\Facades\Storage;

class ManyFilesCast extends AbstractLabelCast
{
    public function convert($value, $column)
    {
        if (!$value) return null;
        
        foreach ($value as $file) {
            $files[] = '<a download href="' . Storage::disk($file['disk'] ?? 'public')->url($file['path'] ?? '') . '" />File</a>';
        }

        return implode('<br>', $files);
    }
}