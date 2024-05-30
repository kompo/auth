<?php

namespace Kompo\Auth\Models\Maps;

use Kompo\Auth\Models\Maps\Address;

trait MorphManyAddresses
{
    /* RELATIONSHIPS */
    public function addresses()
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    public function address()
    {
        return $this->morphOne(Address::class, 'addressable')->latest();
    }

    public function primaryBillingAddress()
    {
        return $this->belongsTo(Address::class, 'primary_billing_address_id');
    }

    public function primaryShippingAddress()
    {
        return $this->belongsTo(Address::class, 'primary_shipping_address_id');
    }

    /* CALCULATED FIELDS */
    public function getPrimaryAddressLabel(): string
    {
        $pa = $this->primaryBillingAddress;
        if (!$pa) {
            return '';
        }

        return $pa->getAddressLabel();
    }

    public function getFirstValidAddress()
    {
        return $this->primaryBillingAddress ?: $this->address()->first();
    }

    public function getFirstValidAddressLabel()
    {
        return $this->getFirstValidAddress()?->getAddressLabel();
    }

    /* ACTIONS */
    public function setAddressableAndMakeBilling(?Address $address)
    {
        if (!$address) {
            return;
        }
        
        $copiedAddress = $this->addresses()->where('external_id', $address->external_id)->first();

        if (!$copiedAddress) {

            $copiedAddress = $address->replicate();
            $copiedAddress->setAddressable($this);
            $copiedAddress->save();

        }

        $this->setPrimaryBillingAddress($copiedAddress->id);
    }

    public function deleteAddresses()
    {
        $this->unsetPrimaryAddresses();

        $this->addresses->each->delete();
    }

    public function deleteAddress()
    {
        $this->unsetPrimaryAddresses();

        $this->address?->delete();
    }

    public function setPrimaryBillingAddress($id)
    {
        $this->primary_billing_address_id = $id;
        $this->save();
    }

    public function setPrimaryShippingAddress($id)
    {
        $this->primary_shipping_address_id = $id;
        $this->save();
    }

    public function unsetPrimaryAddresses()
    {
        if ($this->primary_billing_address_id) {
            $this->primary_billing_address_id = null;
            $this->save();
        }

        if ($this->primary_shipping_address_id) {
            $this->primary_shipping_address_id = null;
            $this->save();
        }
    }
}
