<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\Modal;
use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Models\Teams\Roles\Role;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Models\User;

class AssignRoleModal extends Modal
{
    public $model = TeamRole::class;
    protected $_Title = 'translate.assign-role';
    protected $noHeaderButtons = true;

    protected $defaultTeamId = null;
    protected $defaultUserId = null;

    public function created()
    {
        $this->defaultTeamId = $this->prop('team_id');
        $this->defaultUserId = $this->prop('user_id');
    }

    public function body()
    {
        return _Rows(
            _Select('translate.team')->name('team_id')
                ->searchOptions(2, 'searchTeams')
                ->when($this->defaultTeamId, fn($el) => $el->disabled()->value($this->defaultTeamId))
                ->overModal('select-team'),

            _Select('translate.user')->name('user_id')
                ->searchOptions(2, 'searchUsers')
                ->when($this->defaultUserId, fn($el) => $el->disabled()->value($this->defaultUserId))
                ->overModal('select-user'),

            _Select('translate.role')->name('role')
                ->options(Role::pluck('name', 'id')->toArray())
                ->overModal('select-role'),

            _SubmitButton('translate.save-assignation')->closeModal()->refresh('roles-manager')
        );
    }

    public function searchTeams($search)
    {
        return TeamModel::active()->search($search)->get()->pluck('team_name', 'id');
    }

    public function searchUsers($search)
    {
        return User::hasNameLike($search)
            ->select('id', 'name')
            ->take(50)
            ->get()
            ->pluck('name', 'id');
    }
}