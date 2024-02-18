<?php 

namespace Kompo\Auth\Models\Traits;

use Kompo\Auth\Models\Files\File;

trait MorphManyFilesTrait
{
    /* ACTIONS */
    public function deleteFiles()
    {
        $this->files->each->delete();
    }

    /* RELATIONS */
    public function file()
    {
        return $this->morphOne(File::class, 'fileable');
    }

    public function files()
    {
        return $this->morphMany(File::class, 'fileable');
    }
}