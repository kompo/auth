<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Models\User;
use Kompo\Table;

class UserRolesTable extends Table
{
    const ID = 'user-roles-table';
    public $id = self::ID;

    public $userId;
    protected $user;

    public function created()
    {
        $this->userId = $this->prop('user_id');
        $this->user = User::findOrFail($this->userId);
    }

    public function top()
    {
        return _FlexEnd(
            _Dropdown('translate.actions')->button()
                ->submenu(
                    _Link('translate.assign-role')->class('py-1 px-3')->selfGet('getAssignRoleModal')->inModal(),
                ),
        );
    }

    public function query()
    {
        return $this->user->teamRoles();
    }

    public function headers()
    {
        return [
            _Th('translate.role'),
            _Th('translate.team'),
            _Th('translate.date'),
            _Th('translate.status'),
            _Th()->class('w-8'),
        ];
    }

    public function render($teamRole) {
        return _TableRow(
            _Html($teamRole->roleRelation->name),
            _Rows(
                _Html($teamRole->team->team_name),
            ),
            _Html($teamRole->created_at->format('d/m/Y')),
            _Html($teamRole->status ?? 'status'),

            _TripleDotsDropdown(

            ),
        );
    }

    public function getAssignRoleModal()
    {
        return new AssignRoleModal(null, [
            'user_id' => $this->userId,
        ]);
    }
}