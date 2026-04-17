<?php

namespace Kompo\Auth\Teams;

use Condoedge\Utils\Kompo\Elements\LazyHierarchy;

class TeamRoleSwitcherElement extends LazyHierarchy
{
    public $vueComponent = 'TeamRoleSwitcher';

    public function initialize($label = '')
    {
        parent::initialize($label);

        $currentTeamRole = currentTeamRole();
        $currentTeam = currentTeam();

        $this
            ->hierarchySource('team-role-switcher.bootstrap', 'team-role-switcher.nodes')
            ->hierarchyPaging(20, 80)
            ->loadDeferred()
            ->switchAction(route('team-role-switcher.switch'), 'POST', ['reload' => true])
            ->displayMode('dropdown')
            ->defaultMode(TeamAccessHierarchyBuilder::MODE_TEAMS)
            ->switcherProfile($currentTeamRole?->roleRelation?->profile ?? 1)
            ->switcherCurrent($currentTeam, $currentTeamRole)
            ->switcherLabels($this->defaultLabels())
            ->switcherClasses($this->defaultClasses());

        $this->class('kompo-team-role-switcher-shell');
    }

    public function defaultMode(string $mode)
    {
        return $this->config([
            'defaultMode' => $mode,
        ]);
    }

    public function switcherProfile(int|string|null $profile)
    {
        return $this->config([
            'profile' => $profile,
        ]);
    }

    public function switcherCurrent($team, $teamRole)
    {
        return $this->config([
            'current' => [
                'teamId' => $team?->id,
                'teamName' => $team?->team_name,
                'roleId' => $teamRole?->role,
                'roleName' => $teamRole?->getRoleName(),
            ],
        ]);
    }

    public function switcherClasses(array $classes)
    {
        return $this->config([
            'classes' => array_replace($this->config('classes') ?: [], $classes),
        ]);
    }

    public function switcherLabels(array $labels)
    {
        return $this->config([
            'labels' => array_replace($this->config('labels') ?: [], $labels),
        ]);
    }

    public function displayMode(string $mode)
    {
        return $this->config([
            'displayMode' => $mode,
        ]);
    }

    public function inline()
    {
        return $this->displayMode('inline');
    }

    public function switcherSwitchAction(string $url, string $method = 'POST', array $config = [])
    {
        return $this->switchAction($url, $method, $config);
    }

    public function switchAction(string $url, string $method = 'POST', array $config = [])
    {
        $actions = $this->config('actions') ?: [];

        $actions['switch'] = array_replace($actions['switch'] ?? [], [
            'url' => $url,
            'method' => $method,
        ], $config);

        return $this->config([
            'actions' => $actions,
            'switchUrl' => $url,
        ]);
    }

    protected function defaultLabels(): array
    {
        return [
            'searchPlaceholder' => __('auth.search-placeholder'),
            'teams' => __('auth.switcher-teams'),
            'committees' => __('auth.switcher-committees'),
            'committee' => __('auth.switcher-committee'),
            'committeeShort' => __('auth.switcher-committee-short'),
            'go' => __('auth.switcher-go'),
            'loading' => __('auth.switcher-loading'),
            'empty' => __('auth.switcher-empty'),
            'showMore' => __('auth.switcher-show-more'),
            'error' => __('auth.switcher-error'),
            'switchRole' => __('auth.switcher-switch-role'),
        ];
    }

    protected function defaultClasses(): array
    {
        return [
            'trigger' => '',
            'panel' => '',
            'searchWrapper' => '',
            'searchInput' => '',
            'modes' => '',
            'mode' => '',
            'body' => '',
            'nodeRow' => '',
            'nodeName' => '',
            'committeePill' => '',
            'levelPill' => '',
            'rolePill' => '',
            'goButton' => '',
            'showMore' => '',
        ];
    }
}
