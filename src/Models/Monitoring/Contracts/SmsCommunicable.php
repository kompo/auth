<?php

namespace Kompo\Auth\Models\Monitoring\Contracts;

interface SmsCommunicable extends Communicable 
{
    public function getPhone();
}