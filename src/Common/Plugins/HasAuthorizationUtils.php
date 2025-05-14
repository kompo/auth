<?php

namespace Kompo\Auth\Common\Plugins;

use Condoedge\Utils\Kompo\Plugins\Base\ComponentPlugin;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

class HasAuthorizationUtils extends ComponentPlugin
{
    protected $checkIfUserHasPermission = true;
    
    public function onBoot()
    {
        if (config('kompo-auth.security.bypass-security')) {
            return;
        }
    
        if(!$this->checkPermissions(PermissionTypeEnum::READ)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function authorize()
    {
        if (config('kompo-auth.security.bypass-security')) {
            return true;
        }

        return $this->checkPermissions(PermissionTypeEnum::WRITE);
    }

    protected function checkPermissions($type)
    {
        $this->checkIfUserHasPermission = config('kompo-auth.check-if-user-has-permission') && $this->getComponentProperty('checkIfUserHasPermission');

        if($this->checkIfUserHasPermission && Permission::findByKey(static::getPermissionKey()) && !auth()->user()?->hasPermission(static::getPermissionKey(), $type, $this->getComponentProperty('specificPermissionTeamId'))) {
            return false;
        }

        return true;
    }


    protected function getPermissionKey()
    {
        if ($this->componentHasMethod('getPermissionKey')) {
            return $this->callComponentMethod('getPermissionKey');

        }
        return class_basename($this->component);
    }
}