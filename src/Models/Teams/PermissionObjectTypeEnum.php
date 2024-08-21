<?php

namespace Kompo\Auth\Models\Teams;

enum PermissionObjectTypeEnum: int
{
    case GENERAL = 1;
    case MODEL = 2;
    case FORM = 3;
    case FIELD = 4;
}