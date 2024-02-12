<?php

namespace Kompo\Auth\Teams;

use Kompo\Form;

class RolesMenuSwitcherForm extends Form
{
    protected $availableRoles;
    protected $user;

    public function created()
    {
        $this->user = auth()->user();
        $this->availableRoles = $this->user->collectAvailableRoles();
    }

    public function render()
    {
        if ($this->availableRoles->count() == 1) {
            return;
        }

        return _Rows(
            $this->availableRoles->map(
                fn($role) => _DropdownLink($role)->class($this->user->hasCurrentRole($role) ? 'bg-gray-200 font-bold' : '')
                                ->selfPost('switchCurrentRole', ['current_role' => $role])
                                ->redirect()
            ),
        );
    }

    public function switchCurrentRole($role)
    {
        $this->user->switchRole($role);

        return redirect()->route('dashboard');
    }
}