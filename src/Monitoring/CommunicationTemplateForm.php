<?php

namespace Kompo\Auth\Monitoring;

use Kompo\Auth\Common\Form;
use Kompo\Auth\Common\Modal;
use Kompo\Auth\Models\Monitoring\CommunicationTemplateGroup;
use Kompo\Auth\Models\Monitoring\CommunicationType;

class CommunicationTemplateForm extends Modal
{
    public $model = CommunicationTemplateGroup::class;

    public $style = 'max-height: 95vh;';
    public $class = 'overflow-y-auto mini-scroll';

    public $refreshAfterSubmit = true;
    public $noHeaderButtons = true;

    public function afterSave()
    {
        $this->savePreviousCommunication(request('communication_type') ?? CommunicationType::EMAIL->value);
    }
    
    public function body()
    {
        return _Rows(
            _Input('Name')->name('title'),

            _Select('trigger')->options(collect(CommunicationTemplateGroup::getTriggers())->mapWithKeys(fn($trigger) => [$trigger => $trigger::getName()]))
                ->name('trigger'),

            !$this->model->id ? _Rows(
                _Html('fill the main data to complete the other messages')->class('text-center mb-4'),
            )  : _Rows(
                _ButtonGroup()->options(CommunicationType::optionsWithLabels())
                    ->name('communication_type', false)
                    ->default(CommunicationType::EMAIL->value)
                    ->selfPost('saveAndGetNewForm')
                    ->withAllFormValues()
                    ->inPanel('communication-type-form')
                    ->panelLoading('communication-type-form'),

                _Panel(
                    CommunicationType::EMAIL->handler($this->model->findCommunicationTemplate(CommunicationType::EMAIL->value))->getForm(),
                )->id(id: 'communication-type-form')->class('mb-6'),
            ),

            _SubmitButton(!$this->model->id ? 'next' : 'save')
                ->when($this->model->id, fn($el) => $el->closeModal()->refresh('communications-list')),
        );
    }

    public function saveAndGetNewForm($communicationType)
    {
        $this->savePreviousCommunication(request('previous_communication_type'));

        if (!$communicationType) {
            return _Html('you must select one to see the editor')->class('text-center');
        }

        $oldCommunication = $this->model->findCommunicationTemplate($communicationType);

        $communicationType = CommunicationType::from($communicationType);

        return $communicationType->handler($oldCommunication)->getForm();
    }

    protected function savePreviousCommunication($communicationType)
    {
        if(!$communicationType) return null;

        $oldCommunication = $this->model->findCommunicationTemplate($communicationType);

        $previousCommunicationType = CommunicationType::from($communicationType);
        $previousCommunicationType->handler($oldCommunication)->save( $this->model->id, request()->all());
    }

    public function rules()
    {
        return [
            'title' => 'required',
            'trigger' => 'required',
        ];
    }
}