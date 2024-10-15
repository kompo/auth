<?php

namespace Kompo\Auth\Models\Monitoring\CommunicationHandlers;

use Illuminate\Support\Facades\Mail;
use Kompo\Auth\Models\Monitoring\Contracts\EmailCommunicable;
use Kompo\Auth\Models\Monitoring\Layout\DefaultLayoutEmailCommunicable;

class EmailCommunicationHandler extends AbstractCommunicationHandler
{
    public function communicableInterface()
    {
        return EmailCommunicable::class;
    }

        /**
     * Summary of notifyCommunicables
     * @param \Kompo\Auth\Models\Monitoring\Contracts\EmailCommunicable[] $communicables
     * @param mixed $params
     * @return void
     */
    public function notifyCommunicables(array $communicables, $params = [])
    {
        $layout = $params['layout'] ?? DefaultLayoutEmailCommunicable::class;

        $communicables = collect($communicables)->map(function($communicable) use ($layout) {
            Mail::to($communicable->getEmail())->send(new $layout($this->communication));
        });
    }   
}