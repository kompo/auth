<?php

namespace Kompo\Auth\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        //todo
    }

    public function view(User $user, User $user1)
    {
        //todo
    }

    public function create(User $user)
    {
        //todo
    }

    public function update(User $user, User $user1)
    {
        //todo
    }

    public function managePermissions(User $user, User $user1)
    {
        return $user->hasPermission('managePermissions');
    }

    public function delete(User $user, User $user1)
    {
        //todo
    }
}
