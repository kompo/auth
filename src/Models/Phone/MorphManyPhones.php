<?php

namespace Kompo\Auth\Models\Phone;

trait MorphManyPhones
{
    /* RELATIONSHIPS */
    public function phones()
    {
        return $this->morphMany(Phone::class, 'phonable');
    }

    public function phone()
    {
        return $this->morphOne(Phone::class, 'phonable');
    }

    public function primaryPhone()
    {
        return $this->belongsTo(Phone::class, 'primary_phone_id');
    }

    /* SCOPES */

    /* CALCULATED FIELDS */
    public function getPrimaryPhoneNumber(): string
    {
        $ph = $this->primaryPhone;
        if (!$ph) {
            return '';
        }

        return $ph->getFullLabelWithExtension();
    }

    public function getFirstValidPhone()
    {
        return $this->primaryPhone ?: $this->phone()->first();
    }

    public function getFirstValidPhoneLabel()
    {
        return $this->getFirstValidPhone()?->getFullLabelWithExtension();
    }

    /* ATTRIBUTES */
    public function getPrimaryPhoneNumberAttribute(): string
    {
        $ph = $this->primaryPhone;
        if (!$ph) {
            return '';
        }

        return $ph->number_ph . ($ph->extension_ph ? (' - ext:' . $ph->extension_ph) : '');
    }

    /* ACTIONS */
    public function setPhonableAndMakePrimary(?Phone $phone)
    {
        if (!$phone) {
            return;
        }
        
        $copiedPhone = $this->phones()->matchNumber($phone->number_ph)->first();

        if (!$copiedPhone) {
            $copiedPhone = $phone->replicate();
            $copiedPhone->setPhonable($this);
            $copiedPhone->save();
        }

        $this->setPrimaryPhone($copiedPhone->id);
    }

    public function deletePhones()
    {
        $this->unsetPrimaryPhone();

        $this->phones->each->delete();
    }

    public function deletePhone()
    {
        $this->unsetPrimaryPhone();

        $this->phone?->delete();
    }

    public function setPrimaryPhone($id)
    {
        $this->primary_phone_id = $id;
        $this->save();
    }

    public function unsetPrimaryPhone()
    {
        if ($this->primary_phone_id) {
            $this->primary_phone_id = null;
            $this->save();
        }
    }

    public function createPhoneFromNumberIfNotExists($number)
    {
        $existingPhone = $this->phones()->matchNumber($number)->first();

        if (!$existingPhone) {
            $existingPhone = new Phone();
            $existingPhone->setPhonable($this);
            $existingPhone->setPhoneNumber($number);
            $existingPhone->save();
        }

        return $existingPhone;
    }

    /* ELEMENTS */
    public function getPrimaryPhoneButton()
    {
        $el = _Link()->icon(_Sax('mobile',20))->asPillGrayWhite();

        return $this->primary_phone_number ? $el->href('tel:'.$this->primary_phone_number) : $el->run('() => {alert("No phone found")}');
    }
}
