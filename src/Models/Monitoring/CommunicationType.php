<?php

namespace Kompo\Auth\Models\Monitoring;

use Kompo\Auth\Models\Monitoring\CommunicationHandlers\AbstractCommunicationHandler;
use Kompo\Auth\Models\Monitoring\CommunicationHandlers\DatabaseCommunicationHandler;
use Kompo\Auth\Models\Monitoring\CommunicationHandlers\EmailCommunicationHandler;
use Kompo\Auth\Models\Monitoring\CommunicationHandlers\SmsCommunicationHandler;

enum CommunicationType: int 
{
    use \Kompo\Auth\Models\Traits\EnumKompo;

    case EMAIL = 1;
    case SMS = 2;
    case DATABASE = 3;

    public function label()
    {
        return match ($this) {
            self::EMAIL => __('translate.communication-email'),
            self::SMS => __('translate.communication-sms'),
            self::DATABASE => __('translate.communication-database'),
        };
    }

    /**
     * Summary of handler
     * @param mixed \Kompo\Auth\Models\Monitoring\CommunicationTemplate $communication Communication
     * @return AbstractCommunicationHandler
     */
    public function handler(?CommunicationTemplate $communication): AbstractCommunicationHandler
    {
        return match ($this) {
            self::EMAIL => new EmailCommunicationHandler($communication, $this),
            self::SMS => new SmsCommunicationHandler($communication, $this),
            self::DATABASE => new DatabaseCommunicationHandler($communication, $this),
        };
    }
}