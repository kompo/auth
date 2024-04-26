<?php 

namespace Kompo\Auth\Models\Files;

use Kompo\Auth\Models\Files\File;

trait MorphManyFilesTrait
{
    /* RELATIONS */
    public function file()
    {
        return $this->morphOne(File::class, 'fileable');
    }

    public function files()
    {
        return $this->morphMany(File::class, 'fileable');
    }

    /* CALCULATED FIELDS */
    protected function defaultImageUrl()
    {
        return 'https://ui-avatars.com/api/?name='.urlencode($this->getNameDisplay()).'&color=7F9CF5&background=EBF4FF';
    }

    public function getMainImageUrl()
    {
        return publicUrlFromFileModel($this->file, $this->defaultImageUrl());
    }

    /* ACTIONS */
    public function deleteFiles()
    {
        $this->files->each->delete();
    }

    /* ELEMENTS */
    public function getMainImagePill($class = null)
    {
        return _ImgPill($this->getMainImageUrl(), $class);
    }
}