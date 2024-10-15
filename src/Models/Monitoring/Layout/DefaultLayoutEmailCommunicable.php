<?php

namespace Kompo\Auth\Models\Monitoring\Layout;

use Illuminate\Mail\Mailable;

class DefaultLayoutEmailCommunicable extends Mailable
{
    public $communication;

    public function __construct($communication)
    {
        $this->communication = $communication;
    }

    public function build()
    {
        return $this->subject($this->communication->subject)
                    ->markdown('emails.communication-layout', [
                        'content' => $this->communication->content
                    ]);
    }    
}