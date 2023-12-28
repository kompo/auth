<?php

namespace Kompo\Auth\Models\Traits;

trait HasProfilePhotoTrait
{
    /* DEFINE THESE CONSTANTS ON YOUR MODEL FOR THIS TRAIT TO WORK */
    /*
    public const PHOTO_IMAGE_COLUMN;
    public const SEARCHABLE_NAME_ATTRIBUTE; //defined in HasSearchableNameTrait
    */

    /*ATTRIBUTES */
    public function getProfilePhotoUrlAttribute()
    {
        return $this->decoded_profile_photo ?
            \Storage::disk('public')->url($this->decoded_profile_photo['path']) :
            $this->defaultProfilePhotoUrl();
    }

    /* In case the dev hasn't added ['profile_photo' => 'array'] to the $casts property, we will decode it for them. */
    public function getDecodedProfilePhotoAttribute()
    {
        $profilePhoto = $this->{static::PHOTO_IMAGE_COLUMN};

        return ($profilePhoto && is_string($profilePhoto)) ? json_decode($profilePhoto, true) : $profilePhoto;
    }

    /* CALCULATED FIELDS */
    protected function defaultProfilePhotoUrl()
    {
        return 'https://ui-avatars.com/api/?name='.urlencode($this->{static::SEARCHABLE_NAME_ATTRIBUTE}).'&color=7F9CF5&background=EBF4FF';
    }

    /* ELEMENTS */
    public function getProfilePhotoPill($sizeClass = 'h-9 w-9')
    {
        return _Img('Profile picture')->src($this->profile_photo_url)
            ->class($sizeClass)
            ->class('rounded-full object-cover');
    }

    public function getProfilePhotoImg($height = '10rem')
    {
        return _Img('crm.contact.profile-picture')->src($this->profile_photo_url)
            ->class('w-full object-cover')
            ->style('height:'.$height);
    }
}
