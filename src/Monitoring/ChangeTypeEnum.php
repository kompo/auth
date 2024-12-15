<?php

namespace Kompo\Auth\Monitoring;

enum ChangeTypeEnum: int
{
    case CREATE = 1;
    case UPDATE = 2;    
}