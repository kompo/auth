<?php

use Kompo\Auth\Common\Query;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Models\Teams\PermissionSection;
use Kompo\Auth\Teams\Roles\PermissionSectionRolesTable;

class RoleWrap extends Query
{
    public $model = RoleModel::class;

    protected $permissionSectionId;
    protected $permissionSection;

    public function created()
    {
        $this->permissionSectionId = $this->prop('permission_section_id');

        $this->permissionSection = PermissionSection::findOrFail($this->permissionSectionId);
    }

    public function query()
    {
        return $this->permissionSection->getPermissions();
    }

    public function render($permission)
    {
        return _Rows(
            _Html($permissionSection->name)
        );
    }
}