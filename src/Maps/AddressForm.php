<?php

namespace Kompo\Auth\Maps;

use Kompo\Auth\Common\ModalScroll;
use Kompo\Auth\Models\Maps\Address;

class AddressForm extends ModalScroll
{
    public $model = Address::class;
    public $_Title = 'maps-manage-address';

    protected $addressableId;
    protected $addressableType;

    protected $addressable;

    public function created()
    {
        $this->addressableId = $this->prop('addressable_id');
        $this->addressableType = $this->prop('addressable_type');

        $this->addressable = findOrFailMorphModel($this->addressableId, $this->addressableType);
    }

    public function beforeSave()
    {
        $this->model->addressable_id = $this->addressableId;
        $this->model->addressable_type = $this->addressableType;
    }

    public function afterSave()
    {
        $addressable = findOrFailMorphModel($this->addressableId, $this->addressableType);

        if (request('is_main_billing')) {
            $addressable->setPrimaryBillingAddress($this->model->id);
        }

        if (request('is_main_shipping')) {
            $addressable->setPrimaryShippingAddress($this->model->id);
        }
    }

    public function body()
    {
        $isMainBilling = !$this->addressable?->primary_billing_address_id || $this->addressable?->primary_billing_address_id === $this->model->id;
        $isMainShipping = !$this->addressable?->primary_shipping_address_id || $this->addressable?->primary_shipping_address_id === $this->model->id;

    	return [
            _CanadianPlace(),
            _Columns(
                _Input('maps-address-apt_or_suite')->name('apt_or_suite'),
                _Input('maps-address-description')->name('description_ad'),
            ),
            _Columns(
                _Checkbox('maps-is_main_billing')->name('is_main_billing', false)->default($isMainBilling),
                _Checkbox('maps-is_main_shipping')->name('is_main_shipping', false)->default($isMainShipping),
            ),
        ];
    }

    public function rules()
    {
        return [
            'address1' => 'required',
        ];
    }
}
