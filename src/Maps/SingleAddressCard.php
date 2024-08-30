<?php

namespace Kompo\Auth\Maps;

use Kompo\Auth\Models\Maps\Address;
use Kompo\Form;

class SingleAddressCard extends Form
{
    public $model = Address::class;

    protected $teamId;
    protected $addressableId;
    protected $addressableType;

    protected $addressable;

    public function created()
    {
        $this->teamId = currentTeamId();
        $this->addressableId = $this->prop('addressable_id');
        $this->addressableType = $this->prop('addressable_type');

        if ($address = Address::where('team_id', $this->teamId)
            ->where('addressable_type', $this->addressableType)
            ->where('addressable_id', $this->addressableId)
            ->first()) {
            $this->model($address);
        }
    }

    public function render()
    {
        return _Rows(
            $this->model->exists ?
                _Rows(
                    _Html($this->model->getAddressHtml()),
                    _TextSmGray($this->model->description_ad),
                ) :
                _Html('crm-contact-click-here-to-add-an-address')->class('text-gray-600'),
        )->selfUpdate('getAddressForm', [
            'id' => $this->model->id,
        ])->inModal();
    }

    public function getAddressForm($id = null)
    {
        return new AddressForm($id, [
            'addressable_id' => $this->addressableId,
            'addressable_type' => $this->addressableType,
        ]);
    }
}
