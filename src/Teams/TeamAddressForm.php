<?php

namespace Kompo\Auth\Teams;

class TeamAddressForm extends TeamBaseForm
{
    protected $_Title = 'crm.team-address';
    protected $_Description = 'crm.team-address-desc';

    protected function body()
    {
        return [
            new \Kompo\Auth\Maps\SingleAddressCard([
                'addressable_id' => $this->model->id,
                'addressable_type' => 'team',
            ]),
        ];
    }
}
