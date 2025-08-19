<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Modal;
use Kompo\Auth\Models\Teams\Roles\Role;

class PendingActionsToDeleteRoleModal extends Modal
{
    protected $hasSubmitButton = false;
    protected $_Title = 'translate.pending-actions-to-delete-role';

    public $model = Role::class;

    public function body()
    {
        return _Rows(
            _Rows($this->model->pendingActionsToDeleteEls()),

            _Button('translate.close')->class('mt-4'),
        );
    }
}