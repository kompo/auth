<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Models\Teams\PermissionSection;
use Kompo\Table;

class RolesAndPermissionMatrix extends Table
{
    public $id = 'roles-manager-matrix';

    public $class = 'overflow-x-auto max-w-full mini-scroll pt-5';
    public $itemsWrapperClass = 'w-max !overflow-y-visible';

    public function top()
    {
        return _Rows(            
            _Flex(
                collect([null])->merge(getRoles())->map(function ($role, $i) {
                    return _FlexCenter(
                        _Html($role?->name),
                        !$role ? null : _TripleDotsDropdown(
                            _Link('permissions-edit')->class('py-1 px-2')->selfGet('getRoleForm', ['id' => $role?->id])->inModal()
                        )->class('absolute right-1'),
                )->class('relative bg-white h-full')->when($i == 0, fn($e) => $e->class('border-r border-gray-300'));
                }),
            )->class('roles-manager-rows w-max'),
        );
    }

    public function query()
    {
        return PermissionSection::all();
    }
    
    public function render($permissionSection)
    {
        return new PermissionSectionRolesTable([
           'permission_section_id' => $permissionSection->id
        ]);
    }

    public function getRoleForm($id = null)
    {
        return new (config('kompo-auth.role-form-namespace'))($id);
    }
}