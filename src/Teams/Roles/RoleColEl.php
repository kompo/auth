<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\Form;

class RoleColEl extends Form
{
    protected $roleName;
    protected $roleId;

    public function created()
    {
        // $this->onLoad(fn($e) => $e->run('() => {
        //     console.log("RoleColEl loaded");
        // }'));
    }

    public function render()
    {
        return _FlexCenter(
            // _Hidden()->name('id'),
            // _Html($roleId ?? '&nbsp;'),
            // _TripleDotsDropdown(
            //     _Link('permissions-edit')->class('py-1 px-2')->selfGet('getRoleForm', ['id' => $role?->id])->inModal()
            // )->class('absolute right-1'),
        )->class('relative bg-white h-full');
            //->attr(['data-role-id' => $role?->id]);
    }
}