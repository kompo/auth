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

        $rolesJs = file_get_contents(__DIR__ . '/../../../resources/js/roles-manager.js');

        return str_replace('$spinnerHtml', $spinnerHtml, $rolesJs);
    }
}
