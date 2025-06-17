<?php

namespace Kompo\Auth\Teams;

use Condoedge\Utils\Kompo\ContactInfo\Maps\SingleAddressCard;

class TeamAddressForm extends TeamBaseForm
{
    protected $_Title = 'crm.team-address';
    protected $_Description = 'crm.team-address-desc';

    protected function body()
    {
        return [
            new SingleAddressCard([
                'addressable_id' => $this->model->id,
                'addressable_type' => 'team',
            ]),
        ];
    }
}
