<?php

namespace Kompo\Auth\Models\Teams\Roles;

use Symfony\Component\HttpKernel\Exception\HttpException;

class PermissionException extends HttpException
{
    public function __construct($message = 'You do not have permission to perform this action.')
    {
        parent::__construct(403, $message);
    }
}