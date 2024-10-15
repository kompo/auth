<?php

namespace Kompo\Auth\Models\Monitoring\CommunicationHandlers;

use Illuminate\Support\Facades\Notification;
use Kompo\Auth\Models\Monitoring\Contracts\SmsCommunicable;
use Kompo\Auth\Models\Monitoring\Layout\DefaultLayoutSmsCommunicable;

class SmsCommunicationHandler extends AbstractCommunicationHandler
{
    /**
     * Summary of notifyCommunicables
     * @param \Kompo\Auth\Models\Monitoring\Contracts\SmsCommunicable[] $communicables
     * @param mixed $params
     * @return void
     */
    public function notifyCommunicables(array $communicables, $params = [])
    {
        $layout = $params['layout'] ?? DefaultLayoutSmsCommunicable::class;

        $communicables = collect($communicables)->map(function($communicable) use ($layout) {
            Notification::send($communicable->getPhone(), new $layout($this->communication));
        });
    }   

    public function communicableInterface()
    {
        return SmsCommunicable::class;
    }
}
