<?php

namespace Kompo\Auth\Models\Files;

use Illuminate\Support\Facades\Storage;

trait FileActionsKomponents
{
    /* ATTRIBUTES */
    public function getDisplayAttribute()
    {
        return $this->title ?: $this->name;
    }

    /* CALCULATED FIELDS */
    public function readableSize()
    {
        if(!$this->size && Storage::exists($this->storagePath())){
            $this->size = Storage::size($this->storagePath());
            $this->save();
        }

        $units = collect(['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024]);

        foreach ($units as $unit => $value) {
            if ($this->size >= $value) {
                $sizeFormatted = number_format($this->size / $value, 1) . ' ' . $unit;
                
                return $sizeFormatted;
            }
        }

        return $this->size . ' B';
	}

    public function storagePath()
    {
        return $this->path;
    }

    /* ELEMENTS */
    public function thumbRow()
    {
        return _Flex(
            $this->thumb->class('mr-2 w-14 h-10 shrink-0'),
            _Rows(
                _Html($this->display)->class('text-sm break-all font-medium'),
                _Flex(
                    _Html($this->created_at->diffForHumans())->class('text-xs mr-2'),
                    _Html('&bull;'),
                    _Html($this->readableSize())->class('text-xs ml-2'),
                )->class('text-gray-600')
            )->class('mr-2 flex-initial')
        )->class('cursor-pointer');
    }

    /* ATTRIBUTES */
    public function getThumbAttribute()
    {
        return _Sax($this->file_type_enum->icon(), 24)->class('text-gray-700 flex justify-center items-center');
    }

    protected function getFileTypeEnumAttribute()
    {
        return FileTypeEnum::fromMimeType($this->mime_type);
    }
}
