<?php

namespace Kompo\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Kompo\Auth\Models\Teams\TeamInvitation;
use App\Models\User;

class TeamInvitationAcceptController extends Controller
{
    public function __invoke($id)
    {
        $teamInvitation = TeamInvitation::findOrFail($id);
        $email = $teamInvitation->email;
        $team = $teamInvitation->team;

        if ($user = User::where('email', $email)->first()) {
            
            if (!$team->hasUserWithEmail($email)) {
                $user->createRolesFromInvitation($teamInvitation);
            }

            return redirect()->route('login.password', ['email' => $email]);

        } else {

            return redirect()->to($teamInvitation->getRegisterFromInvitationRoute());
        }
    }
}
