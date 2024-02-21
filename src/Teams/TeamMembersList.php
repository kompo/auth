<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Models\Teams\Team;
use Kompo\Query;

class TeamMembersList extends Query
{
    protected $team;

    public function created()
    {
        $this->team = currentTeam();
    }

    public function query()
    {
        return $this->team->teamRoles()->with('user')
            ->selectRaw('user_id, GROUP_CONCAT(role) as role')->groupBy('user_id');
    }

    public function render($teamRole)
    {
        $user = $teamRole->user;

        return _FlexBetween(
            _Flex4(
                $user->getProfilePhotoPill(),
                _Html($user->name),
            )->href('user.manage', ['id' => $user->id]),
            _FlexEnd4(
                
                auth()->user()->can('addTeamMember', $this->team) ? 
                    _Link($teamRole->role)->class('text-sm text-gray-400 underline')
                        ->selfUpdate('getRoleManagementModal', ['user_id' => $teamRole->user_id])
                        ->inModal() : 
                    _Html($teamRole->role)->class('text-sm text-gray-400'),

                (auth()->id() == $user->id) ? 
                    _Link('Leave')->class('text-sm text-red-500 focus:outline-none')
                        ->selfGet('getLeaveTeamModal')->inModal() : 
                    (auth()->user()->can('removeTeamMember', $this->team) ? 
                        _Link('Remove')->class('text-sm text-red-500 focus:outline-none')
                            ->selfUpdate('getRemoveTeamMemberModal', ['user_id' => $user->id])->inModal() : null)
            )
        )->class('py-4');
    }

    public function getRoleManagementModal($userId)
    {
        return new RoleManagementModal($userId);
    }

    public function getLeaveTeamModal()
    {
        return new LeaveTeamModal();
    }

    public function getRemoveTeamMemberModal($userId)
    {
        return new RemoveTeamMemberModal(['user_id' => $userId]);
    }
}
