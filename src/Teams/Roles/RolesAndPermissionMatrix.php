<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\Query;
use Kompo\Auth\Models\Teams\PermissionSection;

class RolesAndPermissionMatrix extends Query
{
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
        $this->defaultRolesIds = collect(session('latest-roles') ?: getRoles()->take(static::DEFAULT_ROLES_NUM)->pluck('id'));

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
                    ->onInput(fn($e) => $e->run('() => {searchLoadingOn("permission_loading")}')),
                _Spinner()->id('permission_loading')->class('absolute right-4 hidden'),
            )->class('mb-2 relative'),
            _Panel(
                $this->headerRoles($this->defaultRolesIds),
            )->id('roles-header'),
        );
    }

    public static function multiSelect($defaultRolesIds = null)
    {
        return _MultiSelect()->name('roles', false)->placeholder('auth-roles')->options(
            getRoles()->mapWithKeys(fn($r) => [$r->id => _Html($r->name)->attr(['data-role-id' => $r->id])])->toArray()
        )->default($defaultRolesIds ?? [])
        // We're using sort because i got errors using browse althought i used 1 as page number
        ->onChange(fn($e) => $e->run('() => {
                const multiselect = $("input[name=roles]");
                const selectOptions = multiselect.parent().find(".vlTags").find("div[data-role-id]");
                const rolesIds = [...selectOptions].map((o) => $(o).data("roleId"));
                const roleNames = [...selectOptions].map((o) => $(o).text());

                rolesIds.forEach((roleId) => {
                    if (!$("#roles-manager-matrix .roles-manager-rows").find(`div[data-role-id=${roleId}]`).length) {
                        $("#roles-manager-matrix .roles-manager-rows").each((i, e) => {
                            if ($(e).parent().attr("id") == "roles-header") {
                                $(e).append(
                                    $("<div>").attr("data-role-id", roleId).html(`' . _Spinner()->__toHtml() . '`).addClass("bg-white")
                                        .attr("data-void", "true").attr("data-role-name", roleNames[rolesIds.indexOf(roleId)])
                                );
                            } else { 
                                $(e).append(
                                    $("<div>").attr("data-role-id", roleId).text("").attr("data-void", "true")
                                );
                            }
                        });
                    }
                });

                $("#roles-manager-matrix .roles-manager-rows").find("div[data-role-id]").each((i, e) => {
                    if(rolesIds.includes($(e).data("roleId"))) {
                        $(e).show();
                    } else {
                        $(e).hide();
                    }
                });
            }')
        && $e->selfGet('getRoleUpdate')->inPanel('hidden-roles')->run('() => {
            setTimeout(() => {
                $("#roles-header").find("div[data-void]").each((i, e) => {
                    const roleId = $(e).attr("data-role-id");
                    const roleName = $(e).attr("data-role-name");

                    if(!roleId) {
                        return;
                    }

                    $(e).text(roleName);
                    $(e).removeAttr("data-void");

                    e.parentNode.replaceChild(
                        $("div[data-role-header-example=\"" + roleId + "\"]").get(0), e
                    );
                });
                $("#roles-manager-matrix .roles-manager-rows div[data-void=\"true\"]").each((i, e) => {
                    const permissionId = $(e).parent().data("permission-id");
                    const roleId = $(e).data("role-id");
                    const permissionSectionId = $(e).parent().data("permission-section-id");

                    const visual = e;
                    if(!roleId || (!permissionId && !permissionSectionId)) {
                        return;
                    }

                    $("div[data-role-example=\"" + roleId + "-" + permissionId + "\"]").each((i, e) => {
                        visual.parentNode.replaceChild(e, visual);
                    });

                    $("div[data-permission-section-example=\"" + roleId + "-" + permissionSectionId + "\"]").each((i, e) => {
                        visual.parentNode.replaceChild(e, visual);
                    });
                });
            }, 1000);
        }'));
    }

    public function getRoleUpdate()
    {
        $latestRoles = session()->get('latest-roles') ?: [];
        session()->put('latest-roles', request('roles'));

        return new RoleWrap(null, [
            'roles_ids' => collect(request('roles'))->diff($latestRoles)->implode(','),
        ]);
    }

    public function headerRoles($rolesIds = [])
    {
        $rolesIds = !$rolesIds ? [] : $rolesIds;

        if (count($rolesIds) == 0) {
            return _Rows(
                _Html('auth.you-must-select-at-least-one-role')->class('text-center text-lg'), 
            )->class('min-h-[55vh]');
        }
        
        return _Flex(
            collect([null])->merge(getRoles()->whereIn('id', $rolesIds))->map(function ($role, $i) {
                return static::roleHeader($role, $i); 
            }),
        )->class('roles-manager-rows w-max');
    }

    public function query()
    {
        if(!$this->_kompo('currentPage')) $this->currentPage(1);

        return PermissionSection::whereHas('permissions', fn($q) => $q->when(request('permission_name'), fn($q) => $q->where('permission_name', 'like', wildcardSpace(request('permission_name')))))->orderBy('name');
    }
    
    public function render($permissionSection)
    {
        if(is_array(request('roles')) && !count(request('roles'))) {
            return null;
        }

        return new PermissionSectionRolesTable([
           'permission_section_id' => $permissionSection->id,
           'roles_ids' => request('roles') ? implode(',', request('roles')) : $this->defaultRolesIds->implode(',')
        ]);
    }

    public static function roleHeader($role, $i = 1)
    {
        return _FlexCenter(
            _Html($role?->name ?? '&nbsp;'),
            !$role ? null : _TripleDotsDropdown(
                _Link('permissions-edit')->class('py-1 px-2')->selfGet('getRoleForm', ['id' => $role?->id])->inModal()
            )->class('absolute right-1'),
        )->class('relative bg-white h-full')->when($i == 0, fn($e) => $e->class('border-r border-gray-300'))->attr(['data-role-id' => $role?->id]);
    }

    public function getRoleForm($id = null)
    {
        return new (config('kompo-auth.role-form-namespace'))($id);
    }

    public function js()
    {
        return '<<<javascript
            function searchLoadingOn(id) {
                $("#" + id).removeClass("hidden");
            }

            function searchLoadingOff(id) {
                $("#" + id).addClass("hidden");
            }
        ';
    }
}