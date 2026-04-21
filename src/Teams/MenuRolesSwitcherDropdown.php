<?php

namespace Kompo\Auth\Teams;

use Kompo\Form;

class MenuRolesSwitcherDropdown extends Form
{
    public function render()
    {
        if (!auth()->user()) {
            return;
        }

        return TeamRoleSwitcherElement::form();
    }
}
