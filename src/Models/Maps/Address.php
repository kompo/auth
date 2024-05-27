<?php

namespace Kompo\Auth\Models\Maps;

use Kompo\Auth\Models\Model;

class Address extends Model
{
    use \Kompo\Auth\Models\Teams\BelongsToTeamTrait;

    public const BASE_SEPARATOR = '<br>';

    public function save(array $options = [])
    {
        $this->setTeamId();

        parent::save($options);
    }

    /* RELATIONSHIPS */
    public function addressable()
    {
        return $this->morphTo();
    }

    /* SCOPES */
    public function scopeForAddressable($query, $addressableId, $addressableType)
    {
        scopeWhereBelongsTo($query, 'addressable_id', $addressableId);
        scopeWhereBelongsTo($query, 'addressable_type', $addressableType);
    }

    /* ATTRIBUTES */
    public function getAddressLabelAttribute() //Important for displaying loaded value in Place.vue
    {
        return $this->address1.' '.$this->postal_code.' '.$this->city;
    }

    /* CALCULATED FIELDS */
    public function getAddressLabel($full = false)
    {
        return collect([
            $this->address1, 
            $full ? $this->getExtraItems() : null,
            $this->city.', '.$this->state,
            $this->postal_code,
        ])->filter()->implode(Address::BASE_SEPARATOR);
    }

    public function getAddressInline($full = false)
    {
        return str_replace(Address::BASE_SEPARATOR, ', ', $this->getAddressLabel($full));
    }

    public function getAddressGoogleLink()
    {
        return 'https://maps.google.com?&daddr='.urlencode(str_replace(Address::BASE_SEPARATOR, ' ', $this->getAddressLabel()));
    }

    public function getAddressHtml($full = false)
    {
        return '<address class="not-italic">'.$this->getAddressLabel($full).'</address>';
    }

    public function getShortAddressLabel()
    {
        return $this->address1.' '.$this->postal_code.' '.$this->city;
    }

    public function getExtraItems()
    {
        return collect([
            $this->address2, 
            $this->address3,
        ])->filter()->implode(Address::BASE_SEPARATOR);
    }


    /* SCOPES */

    /* ACTIONS */
    public function setAddressable($model)
    {
        $this->addressable_type = $model->getRelationType();
        $this->addressable_id = $model->id;
    }

    /* ELEMENTS */
}
