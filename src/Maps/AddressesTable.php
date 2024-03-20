<?php

namespace Kompo\Auth\Maps;

use Kompo\Auth\Models\Maps\Address;
use Kompo\Table;

class AddressesTable extends Table
{
    protected $teamId;
    protected $addressableId;
    protected $addressableType;

    protected $addressable;

    public function created()
    {
        $this->teamId = currentTeamId();
        $this->addressableId = $this->prop('addressable_id');
        $this->addressableType = $this->prop('addressable_type');

        $this->addressable = findOrFailMorphModel($this->addressableId, $this->addressableType);
    }

    public function query()
    {
        return Address::where('team_id', $this->teamId)
            ->where('addressable_type', $this->addressableType)
            ->where('addressable_id', $this->addressableId);
    }

    public function top()
    {
        return _FlexBetween(
            _TitleCard('maps-address-title'),
            _CreateCard()->selfCreate('getAddressForm')->inModal(),
        )->class('mb-4');
    }

    public function headers()
    {
        return [
            _Th('maps-address-address1'),
            _Th('maps-address-description'),
            _Th('maps-is_main_billing'),
            _Th('maps-is_main_shipping'),
            _Th(),
        ];
    }

    public function render($address)
    {
        return _TableRow(
            _Html($address->getAddressHtml()),
            _Html($address->description_ad),
            _HtmlYesNo($this->addressable->primary_billing_address_id === $address->id),
            _HtmlYesNo($this->addressable->primary_shipping_address_id === $address->id),
            _Delete($address)
        )->selfUpdate('getAddressForm', ['id' => $address->id])->inModal();
    }

    public function getAddressForm($id = null)
    {
        return new AddressForm($id, [
            'addressable_id' => $this->addressableId,
            'addressable_type' => $this->addressableType,
        ]);
    }
}
