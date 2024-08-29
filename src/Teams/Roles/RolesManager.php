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

            function checkMultipleLinkGroupColor(parentCheckbox, role, permissionsIds, separator = ",")
            {
                let lastIndex;

                for (permissionId of permissionsIds.split(separator)) {
                    let selected = $("." + role + '-' + permissionId + ".perm-selected").eq(0)
                    let index = $("." + role + '-' + permissionId).index(selected)
   
                    if (lastIndex !== undefined && lastIndex !== index) {
                        return changeLinkGroupColorToIndex(parentCheckbox, 0)
                    }

                    lastIndex = index
                }

                changeLinkGroupColorToIndex(parentCheckbox, lastIndex)
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
        javascript;
    }
}