<?php

namespace Kompo\Auth\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Kompo\Auth\Events\CommunicableEvent;
use Kompo\Auth\Models\Monitoring\CommunicationTemplateGroup;

class CommunicationTriggeredListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(CommunicableEvent $event)
    {
        $params = array_merge($event->getParams(), [
            'trigger' => $event::class,
        ]);
        
        $groups = CommunicationTemplateGroup::forTrigger($event::class)->hasValid()->get();

        $groups->each->notify($event->getCommunicables(), null, $params);
    }
}