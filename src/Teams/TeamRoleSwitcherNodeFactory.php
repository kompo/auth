<?php

namespace Kompo\Auth\Teams;

class TeamRoleSwitcherNodeFactory
{
    private const ROLE_CHIP_CLASS = 'lazy-hierarchy__chip-button';
    private const ROLE_CHIP_CURRENT_CLASS = 'lazy-hierarchy__chip-button lazy-hierarchy__chip-button--current';

    private const GO_BUTTON_CLASS = 'lazy-hierarchy__icon-button';

    private const GO_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="7" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M3.5 18.2c0-3 2-4.8 4.5-4.8s4.5 1.8 4.5 4.8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M14 8h5m0 0-2-2m2 2-2 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    public function __construct(
        private TeamRoleSwitcherTeamRepository $teams,
        private TeamRoleSwitcherScopeCodec $codec,
    ) {}

    public function context(
        string $scopeKey,
        $team,
        array $access,
        string $mode,
        array $currentPathIds,
        int $childrenCount,
        int $committeeCount,
        $currentTeamRole = null,
    ): HierarchyNodeContext {
        $teamId = (int) $team->id;
        $isCommittee = $this->teams->isCommittee($team);
        $isSelectable = $this->teams->isSelectableForMode($team, $mode);

        return new HierarchyNodeContext(
            id: $this->nodeId($scopeKey, $teamId),
            teamId: $teamId,
            parentId: $team->parent_team_id ? (int) $team->parent_team_id : null,
            teamName: (string) $team->team_name,
            parentName: $team->relationLoaded('parentTeam') ? $team->parentTeam?->team_name : null,
            isCurrent: $isSelectable && $currentTeamRole && $currentTeamRole->team_id == $teamId,
            isInCurrentPath: in_array($teamId, $currentPathIds, true),
            isCommittee: $isCommittee,
            isSelectable: $isSelectable,
            hasChildren: $childrenCount > 0,
            childrenCount: $childrenCount,
            committeeCount: $committeeCount,
            levelLabel: $this->teams->teamLevelLabel($team),
            levelClass: $this->teams->teamLevelClass($team),
            roles: $access['roles'] ?? [],
            switchRole: $access['switchRole'] ?? null,
        );
    }

    public function toPayload(HierarchyNodeContext $ctx, string $switchUrl): array
    {
        return [
            'id' => $ctx->id,
            'teamId' => $ctx->teamId,
            'parentId' => $ctx->parentId,
            'hasChildren' => $ctx->hasChildren,
            'isCurrent' => $ctx->isCurrent,
            'render' => $this->composeRender($ctx, $switchUrl),
        ];
    }

    public function rolePayload(string $roleId, string $roleLabel, int $childrenCount, bool $isCurrent = false): array
    {
        return [
            'id' => $this->codec->roleNodeId($roleId),
            'roleId' => $roleId,
            'hasChildren' => $childrenCount > 0,
            'isCurrent' => $isCurrent,
            'render' => $this->composeRoleRender($roleLabel),
        ];
    }

    public function nodeId(string $scopeKey, int $teamId): string
    {
        return $this->codec->nodeId($scopeKey, $teamId);
    }

    private function composeRender(HierarchyNodeContext $ctx, string $switchUrl)
    {
        $name = _Html(e($ctx->teamName))
            ->class('lazy-hierarchy-node__name')
            ->style('flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.8125rem; font-weight: 600;');

        $metaChildren = array_filter([
            $this->levelPill($ctx),
            ...$this->roleLinks($ctx, $switchUrl),
            $this->switchOnlyLink($ctx, $switchUrl),
        ]);

        $meta = _Flex(...$metaChildren)
            ->class('lazy-hierarchy-node__meta')
            ->style('display: flex; align-items: center; justify-content: flex-end; gap: 0.55rem; max-width: 50%; margin-left: auto;');

        return _Flex($name, $meta)
            ->class('lazy-hierarchy-node__content-row')
            ->style('display: flex; align-items: center; width: 100%; gap: 0.45rem;');
    }

    private function composeRoleRender(string $roleLabel)
    {
        return _Flex(
            _Html(e($roleLabel))
                ->class('lazy-hierarchy-node__name min-w-max')
                ->style('flex: 1 1 auto; min-width: max-content; font-size: 0.875rem; font-weight: 700;')
        )->class('lazy-hierarchy-node__content-row')
            ->style('display: flex; align-items: center; width: 100%; gap: 0.45rem;');
    }

    private function levelPill(HierarchyNodeContext $ctx)
    {
        if (!$ctx->levelLabel) {
            return null;
        }

        return _Pill(e($ctx->levelLabel))
            ->class('lazy-hierarchy-node__level-pill text-white')
            ->class($ctx->levelClass ?: '');
    }

    private function roleLinks(HierarchyNodeContext $ctx, string $switchUrl): array
    {
        $elements = [];

        foreach ($ctx->roles as $role) {
            $elements[] = _Flex(
                $this->roleLink($ctx, $role, $switchUrl),
                $this->goLink($ctx, $role, $switchUrl),
            )->style('display: inline-flex; align-items: center; gap: 0.75rem; min-width: max-content;');
        }

        return $elements;
    }

    private function roleLink(HierarchyNodeContext $ctx, array $role, string $switchUrl)
    {
        $current = $role['isCurrent'] ?? false;
        $class = $current ? self::ROLE_CHIP_CURRENT_CLASS : self::ROLE_CHIP_CLASS;

        return _Link($role['label'] ?? '')
            ->plain()
            ->class('text-sm px-2 py-1 bg-level3/35')
            ->class('min-w-max max-w-none')
            ->style('min-width: max-content; max-width: none;')
            ->class($class)
            ->post($switchUrl, null, ['team_id' => $ctx->teamId, 'role_id' => $role['id'] ?? null])
            ->run("() => location.reload()");
    }

    private function goLink(HierarchyNodeContext $ctx, array $role, string $switchUrl)
    {
        return _Link()
            ->plain()
            ->icon(self::GO_ICON_SVG)
            ->class(self::GO_BUTTON_CLASS)
            ->attr(['title' => __('auth.switcher-switch-role'), 'aria-label' => __('auth.switcher-switch-role')])
            ->post($switchUrl, null, ['team_id' => $ctx->teamId, 'role_id' => $role['id'] ?? null])
            ->run("() => location.reload()");
    }

    private function switchOnlyLink(HierarchyNodeContext $ctx, string $switchUrl)
    {
        $role = $ctx->switchOnlyRole();

        if ($role === null) {
            return null;
        }

        $title = isset($role['label']) && $role['label'] !== ''
            ? __('auth.switcher-switch-role') . ': ' . $role['label']
            : __('auth.switcher-switch-role');

        return _Link()
            ->plain()
            ->icon(self::GO_ICON_SVG)
            ->class(self::GO_BUTTON_CLASS)
            ->attr(['title' => $title, 'aria-label' => $title])
            ->post($switchUrl, null, ['team_id' => $ctx->teamId, 'role_id' => $role['id'] ?? null])
            ->run("() => location.reload()");
    }
}
