<?php

namespace Kompo\Auth\Teams;

use Kompo\Elements\Block;

class TeamRoleSwitcherElement extends Block
{
    public $vueComponent = 'TeamRoleSwitcher';

    public function initialize($label = '')
    {
        parent::initialize($label);

        $currentTeamRole = currentTeamRole();
        $currentTeam = currentTeam();

        $this->config([
            'bootstrapUrl' => route('team-role-switcher.bootstrap'),
            'nodesUrl' => route('team-role-switcher.nodes'),
            'switchUrl' => route('team-role-switcher.switch'),
            'displayMode' => 'dropdown',
            'actions' => [
                'switch' => [
                    'url' => route('team-role-switcher.switch'),
                    'method' => 'POST',
                    'reload' => true,
                ],
            ],
            'defaultMode' => TeamAccessHierarchyBuilder::MODE_TEAMS,
            'profile' => $currentTeamRole?->roleRelation?->profile ?? 1,
            'current' => [
                'teamId' => $currentTeam?->id,
                'teamName' => $currentTeam?->team_name,
                'roleId' => $currentTeamRole?->role,
                'roleName' => $currentTeamRole?->getRoleName(),
            ],
            'labels' => [
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
            ],
            'classes' => [
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
            ],
        ]);

        $this->class('kompo-team-role-switcher-shell');
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
}
