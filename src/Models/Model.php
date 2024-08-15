<?php

namespace Kompo\Auth\Models;

use Kompo\Auth\Models\Teams\PermissionTypeEnum;

class Model extends ModelBase
{
    use \Kompo\Auth\Models\Traits\HasAddedModifiedByTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected static $addReadSecurityRestrictions = false;
    protected static $addWriteSecurityRestrictions = false;
    protected static $restrictByTeam = false;

    const TEAM_ID_COLUMN = 'team_id';
    
    public static function booted()
    {
        if(static::$addReadSecurityRestrictions) {
            static::addGlobalScope('authUserHasPermissions', function ($builder) {
                if (!$this->restrictByTeam && !auth()->user()->hasPermission($this->getTable(), PermissionTypeEnum::READ)) {
                    $builder->whereRaw('1=0');
                }

                $builder->when($this->restrictByTeam, function ($q) {
                    $q->where(static::TEAM_ID_COLUMN, auth()->user()->getTeamsIdsWithPermission($this->getTable(), PermissionTypeEnum::READ));
                });
            });
        }
    }

    protected function checkWritePermissions()
    {
        if(static::$addWriteSecurityRestrictions) {
            if (!$this->restrictByTeam && !auth()->user()->hasPermission($this->getTable(), PermissionTypeEnum::WRITE)) {
                throw new \Exception('translate.no-write-permissions');
            }

            if ($this->restrictByTeam && !auth()->user()->hasPermission($this->getTable(), PermissionTypeEnum::WRITE, $this->team_id)) {
                throw new \Exception('translate.no-write-permissions');
            }
        }
    }

    public function save(array $options = [])
    {
        $this->checkWritePermissions();

        return parent::save($options);
    }

    public function delete()
    {
        $this->checkWritePermissions();

        return parent::delete();
    }
}
