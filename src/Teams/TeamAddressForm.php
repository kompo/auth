<?php

namespace Kompo\Auth\Teams;

class TeamAddressForm extends TeamBaseForm
{
    protected $_Title = 'Team Address';
    protected $_Description = 'The team\'s address.';

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