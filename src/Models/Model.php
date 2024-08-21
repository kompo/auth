<?php

namespace Kompo\Auth\Models;

use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\Roles\PermissionException;

class Model extends ModelBase
{
    use \Kompo\Auth\Models\Traits\HasAddedModifiedByTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected static $readSecurityRestrictions = false;
    protected $deleteSecurityRestrictions = false;
    protected $saveSecurityRestrictions = false;
    protected static $restrictByTeam = false;

    const TEAM_ID_COLUMN = 'team_id';
    
    public static function booted()
    {
        if(static::$readSecurityRestrictions && Permission::findByKey(static::getPermissionKey())) {
            static::addGlobalScope('authUserHasPermissions', function ($builder) {
                if (!static::$restrictByTeam && !auth()->user()->hasPermission(static::getPermissionKey(), PermissionTypeEnum::READ)) {
                    return $builder->whereRaw('1 = 0');
                }

                $builder->when(static::$restrictByTeam, function ($q) {
                    $teamIds = auth()->user()->getTeamsIdsWithPermission(static::getPermissionKey(), PermissionTypeEnum::READ);

                    if (method_exists(static::class, 'scopeForTeams')) {
                        $q->forTeams($teamIds);
                    } else {
                        $q->whereIn(static::TEAM_ID_COLUMN, $teamIds);
                    }
                });
            });
        }
    }

    protected function checkWritePermissions()
    {
        if (!Permission::findByKey(static::getPermissionKey())) {
            return true;
        }

        if (!$this->restrictByTeam && !auth()->user()->hasPermission(static::getPermissionKey(), PermissionTypeEnum::WRITE)) {
            throw new PermissionException(__('permissions-you-do-not-have-write-permissions'));
        }

        if ($this->restrictByTeam && !auth()->user()->hasPermission(static::getPermissionKey(), PermissionTypeEnum::WRITE, $this->team_id)) {
            throw new PermissionException(__('permissions-you-do-not-have-write-permissions'));
        }
    }

    protected static function getPermissionKey()
    {
        return class_basename(static::class);
    }

    public function save(array $options = [])
    {
        $this->saveSecurityRestrictions && $this->checkWritePermissions();

        return parent::save($options);
    }

    public function delete()
    {
        $this->deleteSecurityRestrictions && $this->checkWritePermissions();

        return parent::delete();
    }
}
