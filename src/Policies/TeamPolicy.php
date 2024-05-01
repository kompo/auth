<?php

namespace Kompo\Auth\Policies;

use App\Models\Teams\Team;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TeamPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        //TODO
    }

    public function view(User $user, Team $team)
    {
        //TODO
    }

    public function create(User $user)
    {
        //TODO
    }

    public function update(User $user, Team $team)
    {
        //TODO
    }

    public function addTeamMember(User $user, Team $team)
    {
        return $user->isTeamOwner();
    }

    public function updateTeamMember(User $user, Team $team)
    {
        //TODO
    }

    public function removeTeamMember(User $user, Team $team)
    {
        //TODO
    }

    public function delete(User $user, Team $team)
    {
        //TODO
    }
}
