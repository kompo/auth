<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Common\Modal;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Models\User;

class AssignRoleModal extends Modal
{
    public $model = TeamRole::class;
    protected $_Title = 'translate.assign-role';
    protected $noHeaderButtons = true;

    protected $defaultTeamId = null;
    protected $defaultUserId = null;
    protected $refreshId = UserRolesTable::ID;

    public function created()
    {
        $this->defaultTeamId = $this->prop('team_id');
        $this->defaultUserId = $this->prop('user_id');
    }

    public function beforeSave()
    {
        $hierarchies = [RoleHierarchyEnum::DIRECT];

        if (request('roll_to_child')) {
            array_push($hierarchies, RoleHierarchyEnum::DIRECT_AND_BELOW);
        }

        if (request('roll_to_neighbourg')) {
            array_push($hierarchies, RoleHierarchyEnum::DIRECT_AND_NEIGHBOURS);
        }

        $this->model->role_hierarchy = RoleHierarchyEnum::getFinal($hierarchies);
    }

    public function body()
    {
        return _Rows(
            _Select('translate.team')->name('team_id')
                ->when(!$this->defaultTeamId, fn($el) => $el->searchOptions(2, 'searchTeams'))
                ->when($this->defaultTeamId, fn($el) => $el->disabled()->value($this->defaultTeamId)
                    ->options([$this->defaultTeamId => TeamModel::findOrFail($this->defaultTeamId)->team_name])
                )
                ->onChange(fn($e) => $e->selfGet('getRolesByTeam')->inPanel('roles-select-panel'))
                ->overModal('select-team'),

            _Select('translate.user')->name('user_id')
                ->when(!$this->defaultUserId, fn($el) => $el->searchOptions(2, 'searchUsers'))
                ->when($this->defaultUserId, fn($el) => $el->disabled()->value($this->defaultUserId)
                    ->options([$this->defaultUserId => User::findOrFail($this->defaultUserId)->name])
                )
                ->overModal('select-user'),

            _Panel(
                _Select('translate.role')->name('role'),
            )->id('roles-select-panel'),

            _Toggle('translate.roll-to-child')->name('roll_to_child', false),

            _Toggle('translate.roll-to-neighbourg')->name('roll_to_neighbourg', false),

            _Flex(
                !$this->model->id ? null : _DeleteButton('translate.delete-assignation')->outlined()->byKey($this->model)->class('w-full'),
                _SubmitButton('translate.save-assignation')->closeModal()->refresh($this->refreshId)->class('w-full'),
            )->class('gap-4')
        );
    }

    public function getRolesByTeam($teamId)
    {
        $team = TeamModel::findOrFail($teamId);
        
        return _Select('translate.role')->name('role')
            ->options(RoleModel::all()->pluck('name', 'id')->toArray())
            ->overModal('select-role');
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