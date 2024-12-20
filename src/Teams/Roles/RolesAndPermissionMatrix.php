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
            _Panel()->id('hidden-roles')->class('opacity-0'),
            _MultiSelect()->name('roles', false)->placeholder('auth-roles')->options(
                getRoles()->mapWithKeys(fn($r) => [$r->id => _Html($r->name)->attr(['data-role-id' => $r->id])])->toArray()
            )->default($this->defaultRoles->pluck('id') ?? [])
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
                    });
                    $("#roles-manager-matrix .roles-manager-rows div[data-void=\"true\"]").each((i, e) => {
                        const permissionId = $(e).parent().attr("data-permission-id");
                        const roleId = $(e).attr("data-role-id");

                        const visual = e;
                        if(!roleId || !permissionId) {
                            return;
                        }

                        $("div[data-role-example=\"" + roleId + "-" + permissionId + "\"]").each((i, e) => {
                            visual.innerHTML = e.cloneNode(true).innerHTML;
                        });
                    });
                }, 1000);
            }')),
            _Panel(
                $this->headerRoles($this->defaultRoles->pluck('id')),
            )->id('roles-header'),
        );
    }

    public function getRoleUpdate()
    {
        return new RoleWrap(null, [
            'roles_ids' => implode(',', request('roles')),
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
                return _FlexCenter(
                        _Html($role?->name ?? '&nbsp;'),
                        !$role ? null : _TripleDotsDropdown(
                            _Link('permissions-edit')->class('py-1 px-2')->selfGet('getRoleForm', ['id' => $role?->id])->inModal()
                        )->class('absolute right-1'),
                )->class('relative bg-white h-full')->when($i == 0, fn($e) => $e->class('border-r border-gray-300'))->attr(['data-role-id' => $role?->id]);
            }),
        )->class('roles-manager-rows w-max');
    }

    public function query()
    {
        if(!$this->_kompo('currentPage')) $this->currentPage(1);

        return PermissionSection::whereHas('permissions')->orderBy('name');
    }
    
    public function render($permissionSection)
    {
        if(is_array(request('roles')) && !count(request('roles'))) {
            return null;
        }

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