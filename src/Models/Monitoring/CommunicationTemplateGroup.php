<?php

namespace Kompo\Auth\Models\Monitoring;

use Kompo\Auth\Models\Model;

class CommunicationTemplateGroup extends Model
{
    public function communicationTemplates()
    {
        return $this->hasMany(CommunicationTemplate::class, 'template_group_id');
    }

    // SCOPES
    public function scopeForTrigger($query, $trigger)
    {
        return $query->where('trigger', $trigger);
    }

    public function scopeHasValid($query)
    {
        return $query->whereHas('communicationTemplates', fn($q) => $q->isValid());
    }
    
    public function notify(array $communicables, $type = null, $params = []) 
    {
        $communications = $this->communicationTemplates()
            ->when($type, fn($q) => $q->where('type', $type))
            ->get();

        $communications->each->notify($communicables, $params);
    } 

    public static function deleteOldVoids()
    {
        return self::voids()->whereDate('created_at', '<', now()->subDay())
            ->delete();
    }

    public function scopeVoids($query)
    {
        return $query->doesntHave('communicationTemplates');
    }

    public static function getTriggers()
    {
        return config('kompo-communications.triggers');
    }

    public function findCommunicationTemplate($type)
    {
        return $this->communicationTemplates()->where('type', $type)->first();
    }
}