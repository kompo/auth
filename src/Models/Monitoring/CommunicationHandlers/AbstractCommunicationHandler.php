<?php

namespace Kompo\Auth\Models\Monitoring\CommunicationHandlers;

use Illuminate\Support\Facades\Log;
use Kompo\Auth\Models\Monitoring\CommunicationTemplate;
use Kompo\Auth\Models\Monitoring\CommunicationType;

abstract class AbstractCommunicationHandler
{
    protected $communication;
    protected $type;

    public function __construct($communication, CommunicationType $type)
    {
        $this->communication = $communication ?? new CommunicationTemplate;
        $this->type = $type;
    }
    
    public function formInputs()
    {
        return [
            _Input('Subject')->name('subject', false)->default($this->communication->subject),
            _Textarea('Message')->name('content', false)->default($this->communication->content),
        ];
    }

    final public function getForm()
    {
        return _Rows(
            _Rows($this->formInputs()),

            _Hidden()->name('previous_communication_type', false)->value($this->type),
        );
    }

    public function save($groupId = null, $attributes = [])
    {
        $this->communication->type = $this->type;
        $this->communication->template_group_id = $groupId;

        $this->communication->subject = $attributes['subject'] ?? null;
        $this->communication->content = $attributes['content'] ?? null;

        if ($this->validToSave($attributes)) {
            $this->communication->is_draft = $this->isDraft($attributes) ? 1 : 0;

            $this->communication->save();
        }

        request()->replace([]);
    }

    public function validToSave($attributes = [])
    {
        return (boolean) collect($this->requiredAttributes())->first(function($attribute) use ($attributes) {
            return $attributes[$attribute] ?? $this->communication->$attribute;
        });
    }

    public function isDraft($attributes = []) 
    {
        return !collect($this->requiredAttributes())->every(function($attribute) use ($attributes){
            return $attributes[$attribute] ?? $this->communication->$attribute;
        });
    }

    public function requiredAttributes()
    {
        return ['subject', 'content'];
    }

    abstract public function communicableInterface();
    abstract public function notifyCommunicables(array $communicables, $params = []);

    final public function notify(array $communicables, $params = []) 
    {
        $communicableInterface = $this->communicableInterface();

        $communicables = collect($communicables)->filter(function($communicable) use ($communicableInterface) {
            $condition = in_array($communicableInterface, class_implements($communicable));

            if(!$condition) Log::warning('Communicable does not implement the required interface: ' . $communicableInterface);

            return $condition;
        });

        $this->notifyCommunicables($communicables->all(), $params);
    }
}