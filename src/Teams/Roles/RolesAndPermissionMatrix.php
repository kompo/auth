<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\Query;
use Kompo\Auth\Models\Teams\PermissionSection;

class RolesAndPermissionMatrix extends Query
{
    use RoleRequestsUtils;
    use RoleElementsUtils;

    public $id = 'roles-manager-matrix';
    public $paginationType = 'Scroll';
    public $perPage = 8;

    public $class = 'overflow-x-auto max-w-full mini-scroll pt-5';
    public $itemsWrapperClass = 'w-max overflow-y-auto mini-scroll';
    public $itemsWrapperStyle = 'max-height:50vh;';
    protected $defaultRolesIds;
    const DEFAULT_ROLES_NUM = 4;

    public function created()
    {
        $this->defaultRolesIds = collect(session()->get('latest-roles') ?: getRoles()->take(static::DEFAULT_ROLES_NUM)->pluck('id'));

        session()->put('latest-roles', $this->defaultRolesIds);
    }

    public function top()
    {
        return _Rows(
            _Panel()->id('hidden-roles')->class('opacity-0'),
            _Panel(
                $this->multiSelect($this->defaultRolesIds),
            )->id('multi-select-roles'),
            _Flex(
                _Input()->placeholder('translate.search')->name('permission_name', false)->class('w-full !mb-0')
                    ->browse()->onSuccess(fn($e) => $e->run('() => { setTimeout(() => searchLoadingOff("permission_loading"), 50) }'))
                    ->onInput(fn($e) => $e->run('() => { searchLoadingOn("permission_loading") }')),
                _Spinner()->id('permission_loading')->class('absolute right-4 hidden'),
            )->class('mb-2 relative'),
            _Rows(_Flex(
                collect([null])->merge(getRoles()->whereIn('id', $this->defaultRolesIds))->map(function ($role, $i) {
                    return static::roleHeader($role, $i);
                }),
            )->class('roles-manager-rows w-max'))->id('roles-header'),
        );
    }

    public function query()
    {
        if (!$this->_kompo('currentPage')) $this->currentPage(1);

        return PermissionSection::whereHas('permissions', fn($q) => $q->when(request('permission_name'), fn($q) => $q->where('permission_name', 'like', wildcardSpace(request('permission_name')))))->orderBy('name');
    }

    public function render($permissionSection)
    {
        if (is_array(request('roles')) && !count(request('roles'))) {
            return null;
        }

        return new PermissionSectionRolesTable([
            'permission_section_id' => $permissionSection->id,
            'roles_ids' => request('roles') ? implode(',', request('roles')) : $this->defaultRolesIds->implode(',')
        ]);
    }
}
