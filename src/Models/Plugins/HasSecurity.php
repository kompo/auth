<?php

namespace Kompo\Auth\Models\Plugins;

use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\Roles\PermissionException;
use Condoedge\Utils\Models\Plugins\ModelPlugin;

class HasSecurity extends ModelPlugin
{
    public function onBoot()
    {
        $modelClass = $this->modelClass;

        if($this->hasReadSecurityRestrictions() && Permission::findByKey($this->getPermissionKey())) {
            $modelClass::addGlobalScope('authUserHasPermissions', function ($builder) {
                if (!$this->restrictByTeam() && !auth()->user()->hasPermission($this->getPermissionKey(), PermissionTypeEnum::READ)) {
                    return $builder->whereRaw('1 = 0');
                }

                $builder->when($this->restrictByTeam(), function ($q) {
                    $teamIds = auth()->user()->getTeamsIdsWithPermission($this->getPermissionKey(), PermissionTypeEnum::READ);

                    if (method_exists($this->modelClass, 'scopeForTeams')) {
                        $q->forTeams($teamIds);
                    } else {
                        $q->whereIn($this->modelClass::TEAM_ID_COLUMN, $teamIds);
                    }
                });
            });
        }

        $this->modelClass::saving(function ($model) {
            if (property_exists($model, 'saveSecurityRestrictions') && $model->saveSecurityRestrictions) {
                $this->checkWritePermissions($model);
            }
        });

        $this->modelClass::deleting(function ($model) {
            if (property_exists($model, 'deleteSecurityRestrictions') && $model->deleteSecurityRestrictions) {
                $this->checkWritePermissions($model);
            }
        });
    }

    protected function checkWritePermissions($model)
    {
        if (!Permission::findByKey($this->getPermissionKey())) {
            return true;
        }

        if (!$this->restrictByTeam() && !auth()->user()->hasPermission($this->getPermissionKey(), PermissionTypeEnum::WRITE)) {
            throw new PermissionException(__('permissions-you-do-not-have-write-permissions'));
        }

        if ($this->restrictByTeam() && !auth()->user()->hasPermission($this->getPermissionKey(), PermissionTypeEnum::WRITE, $model->{$model::TEAM_ID_COLUMN})) {
            throw new PermissionException(__('permissions-you-do-not-have-write-permissions'));
        }
    }

    protected function getPermissionKey()
    {
        return class_basename($this->modelClass);
    }

    protected function hasReadSecurityRestrictions()
    {
        if (property_exists($this->modelClass, 'readSecurityRestrictions')) {
            return $this->modelClass::$readSecurityRestrictions;
        }

        return false;
    }

    protected function restrictByTeam()
    {
        if (property_exists($this->modelClass, 'restrictByTeam')) {
            return $this->modelClass::$restrictByTeam;
        }

        return false;
    }

    
}