<?php

namespace Kompo\Auth\Teams;

use Condoedge\Utils\Kompo\Common\Query;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Models\Teams\TeamRole;

class OptionsRolesSwitcher extends Query
{
    public $paginationType = 'Scroll';
    public $perPage = 10;

    public $id = 'kompo-teams-roles-switcher';

    public $itemsWrapperClass = 'overflow-y-auto mini-scroll';
    public $itemsWrapperStyle = 'max-height: 350px';

    public $class = 'p-4';
    public $style = 'min-width: 22rem;';

    private $pageTeams = null;
    private $pageRoles = null;

    public function query()
    {
        $user = auth()->user();
        $profile = request('profile') ?? 1;
        $search = request('search');
        $page = $this->currentPage() ?: 1;
        $offset = ($page - 1) * $this->perPage;

        // Get only the current page's team IDs (sliced from cache)
        $teamsIdsWithRoles = collect($user->getAllTeamIdsWithRolesCached(
            $profile, $search, limit: $this->perPage, offset: $offset
        ));

        $items = $teamsIdsWithRoles->sort()
            ->flatMap(fn($roleIds, $teamId) =>
                collect($roleIds)->filter(fn($rId) => $this->isCurrentRole($rId, $teamId))->map(fn($roleId) => (object)[
                    'id' => $teamId . '_' . $roleId,
                    'team_id' => $teamId,
                    'role_id' => $roleId,
                ])
            )->values();

        // Total count for pagination (cached separately)
        $total = $user->countTeamIdsWithRolesPairs($profile, $search);

        return PaginatedCollection::fromItems($items, $total);
    }

    public function top()
    {
        return _Rows(
            _Select()->class('max-w-2xl min-w-[260px]')->options(config('kompo-auth.profile-enum')::optionsWithLabels())
                ->default(currentTeamRole()?->role?->profile ?? 1)->name('profile', false)
                ->filter(),
            _Input()->placeholder('auth.search-placeholder')->name('search', false)
                ->filter()
                ->debounce(800),
        );
    }

    public function render($item)
    {
        $this->preloadModelsForPage();

        $team = $this->pageTeams->get($item->team_id);
        $role = $this->pageRoles->get($item->role_id);

        if (!$team) {
            return null;
        }

        return $this->getTeamRoleLabel($team, $role)
            ->selfPost('switchToTeamRole', ['team_id' => $team->id, 'role_id' => $role->id])->redirect();
    }

    private function preloadModelsForPage(): void
    {
        if ($this->pageTeams !== null) {
            return;
        }

        // Load Team/Role models only for the current page's items (~10)
        $pageItems = collect($this->query->items());
        $this->pageTeams = TeamModel::whereIn('teams.id', $pageItems->pluck('team_id')->unique())->get()->keyBy('id');
        $this->pageRoles = RoleModel::whereIn('roles.id', $pageItems->pluck('role_id')->unique())->get()->keyBy('id');
    }

    protected function getTeamRoleLabel($team, $role)
    {
        return _FlexBetween(
            _Rows(
                _Html($team->team_name)->class('text-sm font-medium'),
                _Html($role?->name ?: 'Unknown')->class('text-sm text-greenmain opacity-70'),
            ),

            $team->rolePill(),
        )->class('w-72 px-4 py-2 gap-4');
    }

    protected function isCurrentRole($rId, $teamId)
    {
        return !currentTeamRole()
                || currentTeamRole()->team_id != $teamId || currentTeamRole()->role != $rId;
    }

    public function switchToTeamRole($teamId, $roleId)
    {
        if (!auth()->user()->hasAccessToTeam($teamId, $roleId)) {
            abort(403);
        }

        $teamRole = TeamRole::getOrCreateForUser($teamId, auth()->id(), $roleId);

        auth()->user()->switchToTeamRoleId($teamRole->id);

        return refresh();
    }
}