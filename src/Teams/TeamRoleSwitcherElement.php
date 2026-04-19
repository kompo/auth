<?php

namespace Kompo\Auth\Teams;

use Condoedge\Utils\Kompo\Elements\LazyHierarchy;

class TeamRoleSwitcherElement extends LazyHierarchy
{
    public function initialize($label = '')
    {
        parent::initialize($label);

        $currentTeamRole = currentTeamRole();
        $profile = $currentTeamRole?->roleRelation?->profile ?? 1;
        $switchUrl = route('team-role-switcher.switch');

        $this
            ->source(TeamRoleSwitcherHierarchySource::class, ['switchUrl' => $switchUrl])
            ->hierarchyPaging(20, 80)
            ->dropdown($currentTeamRole?->getRoleName())
            ->searchable(__('auth.search-placeholder'));

        if (config('kompo-auth.team_role_switcher.committees_enabled', false)) {
            $this->modes([
                TeamAccessHierarchyBuilder::MODE_TEAMS => __('auth.switcher-teams'),
                TeamAccessHierarchyBuilder::MODE_COMMITTEES => __('auth.switcher-committees'),
            ], 'mode', TeamAccessHierarchyBuilder::MODE_TEAMS);
        }

        $this
            ->hierarchyParam('profile', $profile)
            ->hierarchyLabels([
                'searchPlaceholder' => __('auth.search-placeholder'),
                'loading' => __('auth.switcher-loading'),
                'empty' => __('auth.switcher-empty'),
                'error' => __('auth.switcher-error'),
                'showMore' => __('auth.switcher-show-more'),
            ])
            ->class('kompo-team-role-switcher-shell');
    }
}
