<?php

namespace Kompo\Auth\Http\Controllers;

use Kompo\Auth\Models\Teams\TeamInvitation;
use App\Models\User;
use Illuminate\Routing\Controller as RoutingController;

class TeamInvitationAcceptController extends RoutingController
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
