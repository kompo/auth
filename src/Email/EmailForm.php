<?php

namespace Kompo\Auth\Email;

use Kompo\Auth\Common\ModalScroll;
use Kompo\Auth\Models\Email\Email;

class EmailForm extends ModalScroll
{
    public $model = Email::class;
    public $_Title = 'email-manage-email';

    protected $emailableId;
    protected $emailableType;

    public function created()
    {
        $this->emailableId = $this->prop('emailable_id');
        $this->emailableType = $this->prop('emailable_type');
    }

    public function beforeSave()
    {
        $this->model->emailable_id = $this->emailableId;
        $this->model->emailable_type = $this->emailableType;
    }

    public function afterSave()
    {
        if (request('is_main_email')) {
            findOrFailMorphModel($this->emailableId, $this->emailableType)->setPrimaryEmail($this->model->id);
        }
    }

    public function body()
    {
    	return [
            _Columns(
                _Input('email-email')->name('address_em')->type('email'),
                _Select('email-email-type')->name('type_em')->options(Email::getTypeEmLabels()),
            ),
            _Columns(
                _Checkbox('email-is-main')->name('is_main_email', false)->default(1),
            )
        ];
    }

    public function rules()
    {
        return [
            'type_em' => 'required',
            'address_em' => 'required',
        ];
    }
}
