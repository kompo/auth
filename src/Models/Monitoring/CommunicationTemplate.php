<?php

namespace Kompo\Auth\Models\Monitoring;

use Kompo\Auth\Models\Model;

class CommunicationTemplate extends Model
{
    protected $casts = [
        "type" => CommunicationType::class,
    ];

    public function save(array $options = [])
    {
        $this->is_draft = $this->is_draft ?? ($this->getHandler()->isDraft() ? 1 : 0);

        return parent::save($options);
    }

    public function group()
    {
        return $this->belongsTo(CommunicationTemplateGroup::class, 'template_group_id');
    }

    public function getHandler()
    {
        return $this->type->handler($this);
    }

    // SCOPES
    public function scopeIsValid($query)
    {
        return $query->where('is_draft', 0);
    }
    
    public function notify(array $communicables, $params = []) 
    {
        return $this->getHandler()->notify($communicables, $params);
    }
}