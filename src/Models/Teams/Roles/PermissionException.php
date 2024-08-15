<?php

namespace Kompo\Auth\Models\Teams\Roles;

class PermissionException extends \Exception
{
    public function __construct($message = 'You do not have permission to perform this action.', $code = 403, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}