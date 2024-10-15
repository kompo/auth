<?php

namespace Kompo\Auth\Events;

interface CommunicableEvent
{
    function getParams(): array;
    function getCommunicables(): array;

    static function getName(): string;
}