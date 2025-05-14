<?php

namespace Kompo\Auth\Models\Plugins;

use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\Roles\PermissionException;
use Condoedge\Utils\Models\Plugins\ModelPlugin;

class HasSecurity extends ModelPlugin
{
    protected static $bypassedModels = [];

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
                        $q->whereIn($this->getTeamIdColumn(), $teamIds);
                    }
                });
            });
        }

        $this->modelClass::saving(function ($model) {
            // Skip security check if bypassSecurity property is set
            if ($model->getAttribute('_bypassSecurity') === true || (static::$bypassedModels[spl_object_hash($model)] ?? false)) {
                $model->offsetUnset('_bypassSecurity');

                static::$bypassedModels[spl_object_hash($model)] = true;
                
                return;
            }

            if (property_exists($model, 'saveSecurityRestrictions') && getPrivateProperty($model, 'saveSecurityRestrictions')) {
                $this->checkWritePermissions($model);
            }
        });

        $this->modelClass::deleting(function ($model) {
            // Skip security check if bypassSecurity property is set
            if ($model->getAttribute('_bypassSecurity') === true || (static::$bypassedModels[spl_object_hash($this)] ?? false)) {
                $model->offsetUnset('_bypassSecurity');

                static::$bypassedModels[spl_object_hash($this)] = true;
                
                return;
            }

            if (property_exists($model, 'deleteSecurityRestrictions') && getPrivateProperty($model, 'deleteSecurityRestrictions')) {
                $this->checkWritePermissions($model);
            }
        });

        $this->modelClass::deleted(function ($model) {
            unset(static::$bypassedModels[spl_object_hash($model)]);
        });

        $this->modelClass::saved(function ($model) {
            unset(static::$bypassedModels[spl_object_hash($model)]);
        });
    }

    public function checkWritePermissions($model = null)
    {
        if (!Permission::findByKey($this->getPermissionKey())) {
            return true;
        }

        if (!$this->restrictByTeam() && !auth()->user()->hasPermission($this->getPermissionKey(), PermissionTypeEnum::WRITE)) {
            throw new PermissionException(__('permissions-you-do-not-have-write-permissions'));
        }

        if ($this->restrictByTeam() && !auth()->user()->hasPermission($this->getPermissionKey(), PermissionTypeEnum::WRITE, $model->{$this->getTeamIdColumn()})) {
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
            return getPrivateProperty(new ($this->modelClass), 'readSecurityRestrictions');
        }

        return false;
    }

    protected function restrictByTeam()
    {
        if (property_exists($this->modelClass, 'restrictByTeam')) {
            return getPrivateProperty(new ($this->modelClass), 'restrictByTeam');
        }

        return false;
    }

    protected function getTeamIdColumn()
    {
        if (property_exists($this->modelClass, 'TEAM_ID_COLUMN')) {
            return getPrivateProperty(new ($this->modelClass), 'TEAM_ID_COLUMN');
        }

        return 'team_id';
    }

    public function systemSave($model)
    {
        $model->_bypassSecurity = true;
        $result = $model->save();

        return $result;
    }

    public function systemDelete($model)
    {
        $model->_bypassSecurity = true;
        $result = $model->delete();

        return $result;
    }

    public function managableMethods()
    {
        return [
            'checkWritePermissions',
            'systemSave',
            'systemDelete',
        ];
    }
}