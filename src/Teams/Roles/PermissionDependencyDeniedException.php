<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Models\Teams\Permission;

class PermissionDependencyDeniedException extends \RuntimeException
{
    public function __construct(public readonly Permission $permission, public readonly Permission $required)
    {
        parent::__construct(__('auth-permission-dependency-denied', [
            'permission' => $permission->permission_name ?: $permission->permission_key,
            'required' => $required->permission_name ?: $required->permission_key,
        ]));
    }
}
