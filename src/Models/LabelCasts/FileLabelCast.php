<?php

namespace Kompo\Auth\Models\LabelCasts;

use Illuminate\Support\Facades\Storage;

class FileLabelCast extends AbstractLabelCast
{
    public function convert($value, $column)
    {
        if (!$value) return null;
        
        return '<a href="' . Storage::url($value['path'] ?? '') . '" />File</a>';
    }
}