<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\Form;

class RolesManager extends Form
{
    use RoleRequestsUtils;

    public $id = 'roles-manager';

    public $class = 'pb-16';

    public function render()
    {
        return _Rows(
            _FlexBetween(
                _Html('Roles')->class('text-lg font-bold'),
                _Link('Create Role')->selfGet('getRoleForm')->inModal(),
            ),

            new RolesAndPermissionMatrix(),

        );
    }

    public function js()
    {
        $spinnerHtml = _Spinner()->__toHtml();

        return <<<javascript
            function changeLinkGroupColor(optionClass)
            {
                let current = $("." + optionClass + ".perm-selected").eq(0)
                let next = current.parent().next().find("." + optionClass).eq(0)
                if (!next.length) {
                    next = $("." + optionClass).eq(0)
                }

                $("." + optionClass).addClass("hidden")
                current.removeClass("perm-selected")
                next.removeClass("hidden").addClass("perm-selected")
            }

            function changeLinkGroupColorToIndex(optionClass, index)
            {
                let current = $("." + optionClass + ".perm-selected").eq(0)
                let next = $("." + optionClass).eq(index)

                $("." + optionClass).addClass("hidden")
                current.removeClass("perm-selected")
                next.removeClass("hidden").addClass("perm-selected")
            }

            function changeNullOptionColorToIndex(parentCheckbox, indexes) 
            {
                $("." + parentCheckbox + " .subsection-item").addClass("hidden");

                indexes.forEach(index => {
                    $("." + parentCheckbox + " .subsection-item").eq(index).removeClass("hidden")
                })
            }

            function checkMultipleLinkGroupColor(parentCheckbox, role, permissionsIds, separator = ",")
            {
                let indexes = new Set();

                for (permissionId of permissionsIds.split(separator)) {
                    let selected = $("." + role + '-' + permissionId + ".perm-selected").eq(0)
                    let index = $("." + role + '-' + permissionId).index(selected)

                    indexes.add(index)
                }

                if (indexes.size > 1) {
                    changeNullOptionColorToIndex(parentCheckbox, indexes)
                    return changeLinkGroupColorToIndex(parentCheckbox, 0)
                }

                changeLinkGroupColorToIndex(parentCheckbox, [...indexes][0])
            }

            function changeMultipleLinkGroupColor(parentCheckbox, role, permissionsIds, separator = ",")
            {
                let selected = $("." + parentCheckbox + ".perm-selected").eq(0)
                let index = $("." + parentCheckbox).index(selected)

                let permissions = permissionsIds.split(separator)

                permissions.forEach(permissionId => {
                    changeLinkGroupColorToIndex(role + "-" + permissionId, index)
                })
            }

            function cleanLinkGroupNullOption(name)
            {
                $("." + name + " .subsection-item").addClass("hidden");
                $("." + name + " .subsection-item").eq(0).addClass("hidden");
            }

            function precreateRoleVisuals() {
                const multiselect = $("input[name=roles]");
                const selectOptions = multiselect.parent().find(".vlTags").find("div[data-role-id]");
                const rolesIds = [...selectOptions].map((o) => $(o).data("roleId"));
                const roleNames = [...selectOptions].map((o) => $(o).text());

                rolesIds.forEach((roleId) => {
                    if (!$("#roles-manager-matrix .roles-manager-rows").find(`div[data-role-id=\${roleId}]`).length) {
                        $("#roles-manager-matrix .roles-manager-rows").each((i, e) => {
                            if ($(e).parent().attr("id") == "roles-header") {
                                $(e).append(
                                    $("<div>").attr("data-role-id", roleId).html(`$spinnerHtml`).addClass("bg-white")
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
            }

            function injectRoleContent() {
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
            }

            function searchLoadingOn(id) {
                $("#" + id).removeClass("hidden");
            }

            function searchLoadingOff(id) {
                $("#" + id).addClass("hidden");
            }
        javascript;
    }
}
