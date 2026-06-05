<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Query;
use Kompo\Auth\Models\Teams\PermissionSection;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

class RolesAndPermissionMatrix extends Query
{
    use RoleRequestsUtils;
    use RoleElementsUtils;

    public $id = 'roles-manager-matrix';
    public $paginationType = 'Scroll';
    public $perPage = 10000;

    public $class = 'overflow-x-auto max-w-full mini-scroll overflow-y-hidden pt-5';
    public $itemsWrapperClass = 'w-max overflow-y-auto mini-scroll';
    public $itemsWrapperStyle = 'max-height:50vh; min-height:80px;';
    protected $defaultRolesIds;
    const DEFAULT_ROLES_NUM = 4;

    protected $permissionKey = 'Role';
    protected $permissionType = PermissionTypeEnum::READ;

    public function created()
    {
        $this->defaultRolesIds = collect(session()->get('latest-roles') ?: getRolesOrderedByRelevance()->take(static::DEFAULT_ROLES_NUM)->pluck('id'));

        session()->put('latest-roles', $this->defaultRolesIds->all());
    }

    public function top()
    {
        return _Rows(
            auth()->user()->hasPermission('Role', PermissionTypeEnum::WRITE) ? null :
                $this->warningElNoWritePermissions(),

            _Panel()->id('hidden-roles')->class('opacity-0'),
            _Panel(
                $this->multiSelect($this->defaultRolesIds),
            )->id('multi-select-roles'),
            _Flex(
                _Input()->placeholder('auth.search')->name('permission_name', false)->class('w-full !mb-0')
                    ->browse()->onSuccess(fn($e) => $e->run('() => { setTimeout(() => searchLoadingOff("permission_loading"), 50) }'))
                    ->onInput(fn($e) => $e->run('() => { searchLoadingOn("permission_loading") }')),
                _Spinner()->id('permission_loading')->class('absolute right-4 hidden'),
            )->class('mb-2 relative'),
            _Rows(_Flex(
                collect([null])->merge(getRoles()->whereIn('id', $this->defaultRolesIds))->map(function ($role, $i) {
                    return static::roleHeader($role, $i);
                }),
            )->class('roles-manager-rows w-max bg-white mt-4'))->id('roles-header'),
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

        $rolesIds = request('roles') ?: $this->defaultRolesIds->all();

        // Capture only the section id; the body closure reads CURRENT roles
        // at execute time (when user expands the section), not the matrix-
        // render-time snapshot. Otherwise removed roles linger as phantom
        // columns the next time a collapsed section is expanded.
        $sectionId = $permissionSection->id;

        return _LazyCollapsible(
            $this->sectionHeader($permissionSection, $rolesIds),
            fn() => new PermissionSectionRolesTable([
                'permission_section_id' => $sectionId,
                'roles_ids' => implode(',', collect(request('roles') ?: session('latest-roles') ?: [])->all()),
            ]),
            'rows',
            'subgroup-toggle' . $permissionSection->id,
        );
    }

    protected function warningElNoWritePermissions()
    {
        return _WarningBanner('auth.you-dont-have-permissions-to-change-the-assignations', 'auth.you-dont-have-permissions-to-change-the-assignations-sub')->class('mb-4');
    }
}
