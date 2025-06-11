<?php

namespace Kompo\Auth\Teams;

use Condoedge\Utils\Kompo\Common\Modal;
use Kompo\Auth\Facades\TeamModel;

class TeamForm extends Modal
{
    protected $_Title = 'team-edit-a-team';
    protected $noHeaderButtons = true;
    public $class = 'max-w-lg w-full';

    public $model = TeamModel::class;

    public function created()
    {
        if (!$this->model->id) {
            $this->model->parent_team_id = $this->prop('parent_team_id');
        }
    }

    public function beforeSave()
    {
        //
    }

    public function afterSave()
    {
        $this->model->createOrDeleteMainPhoneFromNumber(request('phone'));
        $this->model->createOrDeleteMainEmailFromAddress(request('email'));
    }

    public function body()
    {
        return _Rows(
            _Rows($this->firstFormPart()),
            _CardLevel5($this->contactFormPart()),
            _Rows($this->lastFormPart()),
            _SubmitButton('team-save')->refresh(['teams-registry-page'])->closeModal()->class('mt-4'),
        );
    }

    protected function firstFormPart()
    {
        return [
            _Image()->name('file')->class('mb-2'),
            _Input('team-code')->name('importcode')->class('mb-2'),
            _Input('team-name')->name('team_name'),
            _CardLevel5(
                _DateTime('team-date-from')->name('active_at')->default(now())->class('mb-2'),
                _DateTime('team-date-to')->name('inactive_at')->class('mb-2'),
            ),
        ];
    }

    protected function contactFormPart()
    {
        return [
            $this->addressInput(),
            _InputEmail('team-email')->name('email', false)->default($this->model->getFirstValidEmailLabel())->class('mb-2'),
            _Input('team-phone')->name('phone', false)->default($this->model->getFirstValidPhoneLabel())->class('mb-2'),
            _Input('team-facebook-url')->name('facebook_url')->class('mb-2'),
            _Input('team-instagram')->name('instagram')->class('mb-2'),
        ];
    }

    protected function addressInput()
    {
        return _Place('team-address')->name('address');
    }

    protected function lastFormPart()
    {
        return [
            _Textarea('team-note')->name('note'),
        ];
    }

    public function rules()
    {
        return [
            'team_name' => 'required',
            'active_at' => 'required|date',
            'inactive_at' => 'nullable|date|after:active_at',
        ];
    }
}
