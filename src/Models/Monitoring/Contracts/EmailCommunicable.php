<?php

namespace Kompo\Auth\Models\Monitoring\Contracts;

interface EmailCommunicable extends Communicable 
{
    public function getEmail();
}