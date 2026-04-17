<?php

namespace Kompo\Auth\Teams;

use Condoedge\Utils\Kompo\Common\Query;
use Kompo\Auth\Models\Teams\TeamRole;

class OptionsRolesSwitcher extends Query
{
    public $hasPagination = false;
    public $bottomPagination = false;
    public $perPage = 1000000;

    public $id = 'kompo-teams-roles-switcher';

    public $itemsWrapperClass = 'overflow-y-auto mini-scroll';
    public $itemsWrapperStyle = 'max-height: 350px';

    public $class = 'p-4';
    public $style = 'width: 31rem; max-width: calc(100vw - 2rem);';

    public function query()
    {
        $user = auth()->user();
        $profile = request('profile') ?? currentTeamRole()?->roleRelation?->profile ?? 1;
        $search = request('search');

        return app(TeamAccessHierarchyBuilder::class)->build(
            $user->getAllTeamIdsWithRolesCached($profile),
            $search,
            request('team_mode', TeamAccessHierarchyBuilder::MODE_TEAMS),
        );
    }

    public function top()
    {
        return _Flex(
            _Input()->placeholder('auth.search-placeholder')->name('search', false)
                ->serverFilter()
                ->debounce(800)
                ->class('min-w-0 flex-1'),
            _Radio()->options([
                TeamAccessHierarchyBuilder::MODE_TEAMS => __('auth.switcher-teams'),
                TeamAccessHierarchyBuilder::MODE_COMMITTEES => __('auth.switcher-committees'),
            ])->default(TeamAccessHierarchyBuilder::MODE_TEAMS)
                ->name('team_mode', false)
                ->serverFilter()
                ->optionClass('mb-0')
                ->class('shrink-0 [&>div]:gap-2 [&>div]:flex [&>div]:flex-col'),
        )->class('items-start gap-3 w-full');
    }

    public function render($node)
    {
        return $this->renderHierarchyNode($node);
    }

    protected function renderHierarchyNode($node)
    {
        $row = $this->getTeamRoleLabel($node);

        if ($node->children->isEmpty()) {
            return $row;
        }

        return _Collapsible(...$node->children->map(fn($child) => $this->renderHierarchyNode($child))->all())
            ->titleLabel($row)
            ->config(['lazyMountChildren' => true])
            ->expandedByDefault($this->shouldExpandNode($node))
            ->wrapperElementsClass($this->childrenWrapperClass($node))
            ->class('gap-0 kompo-team-role-collapsible');
    }

    protected function childrenWrapperClass($node): string
    {
        $baseClass = 'pl-6 ml-3 border-l border-gray-100 gap-0';

        if (request('team_mode') === TeamAccessHierarchyBuilder::MODE_COMMITTEES && !$node->is_selectable) {
            return $baseClass . ' max-h-72 overflow-y-auto mini-scroll';
        }

        return $baseClass;
    }

    protected function getTeamRoleLabel($node)
    {
        return _FlexBetween(
            _Html($node->team->team_name)->class('text-sm font-medium truncate min-w-0'),
            _Flex(
                $node->team->rolePill(),
                $this->nodeSideContent($node),
            )->class('items-center justify-end gap-2 shrink-0'),
        )->class('w-full px-3 py-2 gap-4 text-greenmain hover:bg-gray-50')
            ->class($this->isCurrentTeamNode($node) ? 'bg-level1 bg-opacity-30' : '');
    }

    protected function nodeSideContent($node)
    {
        if ($this->shouldShowCommitteeCount($node)) {
            return _Html($this->committeeCountLabel($node->children_count))
                ->class('text-xs text-gray-500 whitespace-nowrap');
        }

        return $this->roleActions($node);
    }

    protected function shouldShowCommitteeCount($node): bool
    {
        return request('team_mode') === TeamAccessHierarchyBuilder::MODE_COMMITTEES
            && !$node->is_selectable
            && ($node->children_count ?? 0) > 0;
    }

    protected function committeeCountLabel(int $count): string
    {
        $translationKey = $count === 1 ? 'auth.switcher-committee' : 'auth.switcher-committees';

        return $count . ' ' . __($translationKey);
    }

    protected function roleActions($node)
    {
        if ($node->roles->isEmpty()) {
            return null;
        }

        return _Flex($node->roles->map(fn($role) => $this->roleAction($node->team, $role)))
            ->class('items-center justify-end gap-2 flex-wrap');
    }

    protected function roleAction($team, $role)
    {
        $roleId = $role->id;
        $isCurrent = currentTeamRole()
            && currentTeamRole()->team_id == $team->id
            && currentTeamRole()->role == $roleId;

        return _Flex(
            $this->roleSwitchLink($team, $roleId, $role->name ?: $roleId, $isCurrent),
            $this->roleSwitchLink($team, $roleId, null, $isCurrent, true, 'cursor'),
        )->class('items-center gap-1');
    }

    protected function roleSwitchLink($team, string $roleId, ?string $label, bool $isCurrent, bool $isButton = false, $icon = null)
    {
        return _Link($label)->when($icon, fn($link) => $link->icon($icon))
            ->class('text-sm')
            ->selfPost('switchToTeamRole', ['team_id' => $team->id, 'role_id' => $roleId])
            ->redirect();
    }

    protected function shouldExpandNode($node): bool
    {
        return request('search') || $this->containsCurrentTeam($node);
    }

    protected function containsCurrentTeam($node): bool
    {
        if ($this->isCurrentTeamNode($node)) {
            return true;
        }

        return $node->children->contains(fn($child) => $this->containsCurrentTeam($child));
    }

    protected function isCurrentTeamNode($node): bool
    {
        return currentTeamRole() && currentTeamRole()->team_id == $node->team->id;
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
