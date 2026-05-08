<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Query;
use Kompo\Auth\Models\Teams\PermissionSection;

class RolesAndPermissionMatrix extends Query
{
    use RoleRequestsUtils;
    use RoleElementsUtils;

    public $id = 'roles-manager-matrix';
    public $paginationType = 'Scroll';
    public $perPage = 10000;

    public $class = 'overflow-x-auto max-w-full mini-scroll pt-5';
    public $itemsWrapperClass = 'w-max overflow-y-auto mini-scroll';
    public $itemsWrapperStyle = 'max-height:50vh; min-height:80px;';
    protected $defaultRolesIds;
    const DEFAULT_ROLES_NUM = 4;

    protected $permissionKey = 'Role';

    public function created()
    {
        $this->defaultRolesIds = collect(session()->get('latest-roles') ?: getRolesOrderedByRelevance()->take(static::DEFAULT_ROLES_NUM)->pluck('id'));

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
                _Input()->placeholder('auth.search')->name('permission_name', false)->class('w-full !mb-0')
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

        $rolesIds = request('roles') ?: $this->defaultRolesIds->all();

        return _Rows(
            $this->sectionHeader($permissionSection, $rolesIds),
            // Hidden wrapper containing a skeleton. Slide-toggle target lives
            // on the WRAPPER (not the panel) so AJAX-driven Vue rerenders of
            // the inner panel don't reset jQuery's inline display. _Div (block)
            // animates cleanly, unlike _Rows (flex). Tailwind's `hidden` class
            // gives the initial display:none; jQuery slideToggle takes over
            // from the first click onward via inline style.
            _Div(
                _Panel($this->rowsSkeleton())
                    ->id('section-rows-' . $permissionSection->id),
            )
            ->class('subgroup-block' . $permissionSection->id)
            ->class('hidden'),
        );
    }

    protected function rowsSkeleton()
    {
        return _Rows(
            ...collect(range(1, 4))->map(fn() =>
                _Rows()->class('h-8 bg-gray-200 rounded my-1 animate-pulse')
            ),
        )->class('p-2 opacity-60');
    }
}
