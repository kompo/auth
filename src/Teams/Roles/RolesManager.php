<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\Form;

class RolesManager extends Form
{
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
        javascript;
    }

    public function getRoleForm($id = null)
    {
        return new (config('kompo-auth.role-form-namespace'));
    }
}