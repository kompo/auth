<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Facades\UserModel;
use Condoedge\Utils\Kompo\Common\Modal;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Facades\TeamModel;
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
        $this->model->role = $role->id;

        TeamRole::manageTerminateAssignationFromRequest([static::class, 'terminateTeamRole']);

        if (TeamRole::where('team_id', request('team_id'))
            ->where('user_id', request('user_id'))
            ->where('role', $this->model->role)
            ->exists()
        ) {
            abort(403, __('error-role-already-assigned'));
        }
    }

    public function body()
    {
        return _Rows(
            $this->teamSelector(),

            _Select('permissions-user')->name('user_id')->required()
                ->when(!$this->defaultUserId, fn($el) => $el->searchOptions(2, 'searchUsers'))
                ->when(
                    $this->defaultUserId,
                    fn($el) => $el->disabled()->value($this->defaultUserId)
                        ->options([$this->defaultUserId => UserModel::findOrFail($this->defaultUserId)->name])
                )
                ->overModal('select-user'),

            _Panel()->id('roles-select-panel'),

            _Panel()->id('role-warning'),

            $this->warningCard('permissions-roll-down-warning', 'teams.role-roll-down-warning'),
            $this->warningCard('permissions-roll-to-neighbour-warning', 'teams.role-roll-to-neighbour-warning'),

            _Flex(
                !$this->model->id ? null : _DeleteButton('permissions-delete-assignation')->outlined()->byKey($this->model)->class('w-full'),
                _SubmitButton('permissions-save-assignation')
                    ->closeModal()->refresh($this->refreshId)
                    ->class('w-full'),
            )->class('gap-4')
        );
    }

    public function teamSelector()
    {
        return _Select('permissions-team')->name('team_id')->required()
            ->when(!$this->defaultTeamId, fn($el) => $el->searchOptions(2, 'searchTeams'))
            ->when(
                $this->defaultTeamId,
                fn($el) => $el->disabled()->value($this->defaultTeamId)
                    ->options([$this->defaultTeamId => TeamModel::findOrFail($this->defaultTeamId)->team_name])
            )
            ->onChange(fn($e) => $e->selfGet('getSelectRolesByTeam')->inPanel('roles-select-panel'))
            ->overModal('select-team');
    }

    protected function warningCard(string $id, string $messageKey)
    {
        return _Flex(
            _Sax('info-circle', 20)->class('text-warning shrink-0 mt-0.5'),
            _Html($messageKey)->class('text-sm'),
        )->id($id)
         ->class('hidden gap-2 items-start p-3 rounded-lg bg-warning bg-opacity-10 border border-warning mb-2');
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
                    'role-id' => $role->id,
                ])
            ]))
            ->overModal('select-role')
            ->onChange(fn($e) => $e->run('({value, el}) => {
                const parsedValue = value[0]?.value ?? null;

                if (!parsedValue) return;

                const selectedOption = document.querySelector(`.vlOption>div[role-id="${parsedValue}"]`);
                const acceptRollToChild = selectedOption?.getAttribute("data-accept-roll-to-child") == 1;
                const acceptRollToNeighbourg = selectedOption?.getAttribute("data-accept-roll-to-neighbourg") == 1;

                $("#permissions-roll-down-warning").toggleClass("hidden", !acceptRollToChild);
                $("#permissions-roll-to-neighbour-warning").toggleClass("hidden", !acceptRollToNeighbourg);
            }') && $e->selfPost('checkIfItHasWarning')->withAllFormValues()->inPanel('role-warning'));
    }

    public function checkIfItHasWarning()
    {
        $roleId = request('role');
        $teamId = request('team_id');

        return TeamRole::checkIfIsWarningEls($roleId, $teamId);
    }

    static public function terminateTeamRole($teamRole)
    {
        $teamRole->terminate();
    }

    protected function getRolesByTeam($teamId)
    {
        return RoleModel::all();
    }

    public function searchTeams($search)
    {
        $teamsIds = auth()->user()->getAllAccessibleTeamIds($search);

        return TeamModel::whereIn('id', $teamsIds)
            ->take(30)
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
