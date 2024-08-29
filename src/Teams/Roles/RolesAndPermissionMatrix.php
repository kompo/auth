<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\Query;
use Kompo\Auth\Models\Teams\PermissionSection;

class RolesAndPermissionMatrix extends Query
{
    public $id = 'roles-manager-matrix';
    public $paginationType = 'Scroll';
    public $perPage = 10;

    public $class = 'overflow-x-auto max-w-full mini-scroll pt-5';
    public $itemsWrapperClass = 'w-max overflow-y-auto mini-scroll';
    public $itemsWrapperStyle = 'max-height:50vh;';
    protected $defaultRoles;
    const DEFAULT_ROLES_NUM = 4;

    public function created()
    {
        $this->defaultRoles = getRoles()->take(static::DEFAULT_ROLES_NUM);
    }

    public function top()
    {
        return _Rows(
            _MultiSelect()->name('roles', false)->placeholder('auth-roles')->options(
                getRoles()->pluck('name', 'id')->toArray()
            )->default($this->defaultRoles->pluck('id') ?? [])
            ->onChange(fn($e) => $e->browse(null, 1) && $e->selfPost('headerRoles')->inPanel('roles-header')),
            _Panel(
                $this->headerRoles($this->defaultRoles->pluck('id')),
            )->id('roles-header'),
        );
    }

    public function headerRoles($rolesIds)
    {
        $rolesIds = !$rolesIds ? [] : $rolesIds;
        
        return _Flex(
            collect([null])->merge(getRoles()->whereIn('id', $rolesIds))->map(function ($role, $i) {
                return _FlexCenter(
                        _Html($role?->name ?? '&nbsp;'),
                        !$role ? null : _TripleDotsDropdown(
                            _Link('permissions-edit')->class('py-1 px-2')->selfGet('getRoleForm', ['id' => $role?->id])->inModal()
                        )->class('absolute right-1'),
                )->class('relative bg-white h-full')->when($i == 0, fn($e) => $e->class('border-r border-gray-300'));
            }),
        )->class('roles-manager-rows w-max');
    }

    public function query()
    {
        return PermissionSection::all();
    }
    
    public function render($permissionSection)
    {
        return new PermissionSectionRolesTable([
           'permission_section_id' => $permissionSection->id,
           'roles_ids' => request('roles') ? implode(',', request('roles')) : $this->defaultRoles->implode('id', ',')
        ]);
    }

    public function getRoleForm($id = null)
    {
        return new (config('kompo-auth.role-form-namespace'))($id);
    }
}