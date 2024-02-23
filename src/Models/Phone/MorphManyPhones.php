<?php

namespace App\Models\Phone;

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

    /* CALCULATED FIELDS */
    public function getPrimaryPhoneNumber(): string
    {
        $ph = $this->primaryPhone;
        if (!$ph) {
            return '';
        }

        return $ph->number_ph . ($ph->extension_ph ? (' - ext:' . $ph->extension_ph) : '');
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

    /* ELEMENTS */
    public function getPrimaryPhoneButton()
    {
        $el = _Link()->icon(_Sax('mobile',20))->asPillGrayWhite();

        return $this->primary_phone_number ? $el->href('tel:'.$this->primary_phone_number) : $el->run('() => {alert("No phone found")}');
    }
}
