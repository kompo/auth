<?php

namespace Kompo\Auth\Common;

use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

trait HasAuthorizationUtils 
{
    protected $checkIfUserHasPermission = true;
    protected $specificPermissionTeamId = null;
    
    public function booted()
    {
        if(!$this->checkPermissions(PermissionTypeEnum::READ)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function authorize()
    {
        return $this->checkPermissions(PermissionTypeEnum::WRITE);
    }

    protected function checkPermissions($type)
    {
        $this->checkIfUserHasPermission = config('kompo-auth.check-if-user-has-permission') && $this->checkIfUserHasPermission;
        
        if($this->checkIfUserHasPermission && Permission::findByKey(class_basename($this)) && !auth()->user()->hasPermission(class_basename($this), $type, $this->specificPermissionTeamId)) {
            return false;
        }

        return true;
    }
}