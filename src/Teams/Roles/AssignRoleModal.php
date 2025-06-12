<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Modal;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Models\User;

class AssignRoleModal extends Modal
{
    public $model = TeamRole::class;
    protected $_Title = 'permissions-assign-role';
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
        $role = RoleModel::findOrFail(request('role'));

        $hierarchies = [RoleHierarchyEnum::DIRECT];

        if (request('roll_to_child') && $role->accept_roll_to_child) {
            array_push($hierarchies, RoleHierarchyEnum::DIRECT_AND_BELOW);
        }

        if (request('roll_to_neighbourg') && $role->accept_roll_to_neighbourg) {
            array_push($hierarchies, RoleHierarchyEnum::DIRECT_AND_NEIGHBOURS);
        }

        $this->model->role_hierarchy = RoleHierarchyEnum::getFinal($hierarchies);
    }

    public function body()
    {
        return _Rows(
            _Select('permissions-team')->name('team_id')->required()
                ->when(!$this->defaultTeamId, fn($el) => $el->searchOptions(2, 'searchTeams'))
                ->when(
                    $this->defaultTeamId,
                    fn($el) => $el->disabled()->value($this->defaultTeamId)
                        ->options([$this->defaultTeamId => TeamModel::findOrFail($this->defaultTeamId)->team_name])
                )
                ->onChange(fn($e) => $e->selfGet('getSelectRolesByTeam')->inPanel('roles-select-panel'))
                ->overModal('select-team'),

            _Select('permissions-user')->name('user_id')->required()
                ->when(!$this->defaultUserId, fn($el) => $el->searchOptions(2, 'searchUsers'))
                ->when(
                    $this->defaultUserId,
                    fn($el) => $el->disabled()->value($this->defaultUserId)
                        ->options([$this->defaultUserId => User::findOrFail($this->defaultUserId)->name])
                )
                ->overModal('select-user'),

            _Panel()->id('roles-select-panel'),

            // hidden class will be removed by js if the selected role has accept_roll_to_child
            _Toggle('permissions-roll-down')->name('roll_to_child', false)
                ->id('permissions-roll-down'),

            // hidden class will be removed by js if the selected role has accept_roll_to_neighbourg
            _Toggle('permissions-roll-to-neighbour')->name('roll_to_neighbourg', false)
                ->id('permissions-roll-to-neighbour'),

            // Doing this instead of puttin hidden in the toggle, because they will get reloaded with that class on every change
            _Hidden()->name('hide', false)->onLoad(fn($e) => $e->run('() => {
                $("#permissions-roll-down").closest(".vlToggle").addClass("hidden");
                $("#permissions-roll-to-neighbour").closest(".vlToggle").addClass("hidden");
            }')),

            _Flex(
                !$this->model->id ? null : _DeleteButton('permissions-delete-assignation')->outlined()->byKey($this->model)->class('w-full'),
                _SubmitButton('permissions-save-assignation')->closeModal()->refresh($this->refreshId)->class('w-full'),
            )->class('gap-4')
        );
    }

    public function getSelectRolesByTeam($teamId)
    {
        return _Select('permissions-role')->name('role')->required()
            ->options($this->getRolesByTeam($teamId)->mapWithKeys(fn($role) => [
                $role->id =>
                _Html($role->name)->attr([
                    // To manage with js the displaying of the toggles
                    'data-accept-roll-to-child' => $role->accept_roll_to_child,
                    'data-accept-roll-to-neighbourg' => $role->accept_roll_to_neighbourg,
                ])
            ]))
            ->overModal('select-role')
            ->onChange(fn($e) => $e->run('() => {
                const selected = $("#roles-select-panel").find(".vlSelected>div");

                const acceptRollToChild = selected?.data("accept-roll-to-child") || false;
                const acceptRollToNeighbourg = selected?.data("accept-roll-to-neighbourg") || false;

                if (acceptRollToChild) {
                    $("#permissions-roll-down").closest(".vlToggle").removeClass("hidden");
                } else {
                    $("#permissions-roll-down").closest(".vlToggle").addClass("hidden");
                }

                if (acceptRollToNeighbourg) {
                    $("#permissions-roll-to-neighbour").closest(".vlToggle").removeClass("hidden");
                } else {
                    $("#permissions-roll-to-neighbour").closest(".vlToggle").addClass("hidden");
                }
            }'));
    }

    protected function getRolesByTeam($teamId)
    {
        return RoleModel::all();
    }

    public function searchTeams($search)
    {
        $teamsIds = auth()->user()->getAllAccessibleTeamIds($search, 30);

        return TeamModel::whereIn('id', $teamsIds)
            ->pluck('team_name', 'id');
    }

    public function searchUsers($search)
    {
        return User::hasNameLike($search)
            ->select('id', 'name')
            ->take(50)
            ->get()
            ->pluck('name', 'id');
    }

    public function rules()
    {
        return [
            'team_id' => 'required|exists:teams,id',
            'user_id' => 'required|exists:users,id',
            'role' => 'required|exists:roles,id',
            'roll_to_child' => 'boolean',
            'roll_to_neighbourg' => 'boolean',
        ];
    }
}
