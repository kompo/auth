<?php

namespace Kompo\Auth\Models\Monitoring\Contracts;

interface DatabaseCommunicable extends Communicable 
{
    public function getId();
    public function hasTeam($teamId);
}