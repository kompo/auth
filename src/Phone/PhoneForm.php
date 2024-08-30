<?php

namespace Kompo\Auth\Phone;

use Kompo\Auth\Common\ModalScroll;
use Kompo\Auth\Models\Phone\Phone;

class PhoneForm extends ModalScroll
{
    public $model = Phone::class;
    public $_Title = 'phone-manage-phone';

    protected $phonableId;
    protected $phonableType;

    public function created()
    {
        $this->phonableId = $this->prop('phonable_id');
        $this->phonableType = $this->prop('phonable_type');
    }

    public function beforeSave()
    {
        $this->model->phonable_id = $this->phonableId;
        $this->model->phonable_type = $this->phonableType;
    }

    public function afterSave()
    {
        if (request('is_main_phone')) {
            findOrFailMorphModel($this->phonableId, $this->phonableType)->setPrimaryPhone($this->model->id);
        }
    }

    public function body()
    {
    	return [
            _Columns(
                _Input('crm-phone-number')->name('number_ph')->type('tel'),
                _Select('crm-phone-type')->name('type_ph')->options(Phone::getTypePhLabels()),
            ),
            _Columns(
                _Checkbox('crm-phone-is-main')->name('is_main_phone', false)->default(1),
            ),
        ];
    }

    public function rules()
    {
        return [
            'type_ph' => 'required',
            'number_ph' => 'required',
        ];
    }
}
