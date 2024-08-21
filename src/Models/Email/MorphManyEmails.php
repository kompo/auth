<?php

namespace Kompo\Auth\Models\Email;

trait MorphManyEmails
{
    /* RELATIONSHIPS */
    public function emails()
    {
        return $this->morphMany(Email::class, 'emailable');
    }

    public function email()
    {
        return $this->morphOne(Email::class, 'emailable')->latest();
    }

    public function primaryEmail()
    {
        return $this->belongsTo(Email::class, 'primary_email_id');
    }

    /* CALCULATED FIELDS */
    public function getPrimaryEmailAddress(): string
    {
        return $this->primaryEmail?->getEmailLabel() ?: '';
    }

    public function getFirstValidEmail()
    {
        return $this->primaryEmail ?: $this->email()->first();
    }

    public function getFirstValidEmailLabel()
    {
        return $this->getFirstValidEmail()?->getEmailLabel();
    }

    /* ATTRIBUTES */
    public function getPrimaryEmailAddressAttribute(): string
    {
        return $this->primaryEmail?->getEmailLabel() ?: '';
    }

    /* ACTIONS */
    public function deleteEmails()
    {
        $this->unsetPrimaryEmail();

        $this->emails->each->delete();
    }

    public function deleteEmail()
    {
        $this->unsetPrimaryEmail();

        $this->email?->delete();
    }

    public function setPrimaryEmail($id)
    {
        $this->primary_email_id = $id;
        $this->save();
    }

    public function unsetPrimaryEmail()
    {
        if ($this->primary_email_id) {
            $this->primary_email_id = null;
            $this->save();
        }
    }

    public function createOrDeleteMainEmailFromAddress($address)
    {
        $existingEmail = $this->email;

        if (!$address){
            $existingEmail?->delete();
        } else {
            if (!$existingEmail || !$existingEmail->isSameAddress($address)) {
                $this->createEmailFromAddress($address);
            }
        }
    }

    public function createEmailFromAddress($address)
    {
        $existingEmail = new Email();
        $existingEmail->setEmailable($this);
        $existingEmail->setEmailAddress($address);
        $existingEmail->save();        
    }

    /* ELEMENTS */
    public function getPrimaryEmailButton()
    {
        $el = _Link()->icon(_Sax('sms',20))->asPillGrayWhite();

        return $this->primary_email_address ? $el->href('mailto:'.$this->primary_email_address) : $el->run('() => {alert("No email found")}');
    }
}
