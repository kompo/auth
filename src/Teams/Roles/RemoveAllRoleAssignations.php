<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\Modal;

class RemoveAllRoleAssignations extends Modal
{
    public function body()
    {
        return _Rows(
            _Html('hows'),
        );
    }
}