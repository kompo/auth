<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\WhiteTable;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Models\User;

class UserRolesTable extends WhiteTable
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
        )->class('mb-3');
    }

    public function query()
    {
        return $this->user->teamRoles()->withTrashed()->orderBy('deleted_at', 'asc')->orderBy('created_at', 'desc')->get();
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
            _Html($teamRole->roleRelation->name)->class('font-semibold'),
            $teamRole->team->getFullInfoTableElement(),
            _Html($teamRole->created_at->format('d/m/Y')),
            $teamRole->statusPill(),

            _TripleDotsDropdown(
                !$teamRole->status->canBeFinished() ? null : _Link('translate.terminate')->class('py-1 px-3')->selfPost('terminateRole', ['team_role_id' => $teamRole->id])->refresh(),
            ),
        );
    }

    public function terminateRole($teamRoleId)
    {
        $teamRole = TeamRole::findOrFail($teamRoleId);
        $teamRole->delete();
    }

    public function getAssignRoleModal()
    {
        return new AssignRoleModal(null, [
            'user_id' => $this->userId,
        ]);
    }
}