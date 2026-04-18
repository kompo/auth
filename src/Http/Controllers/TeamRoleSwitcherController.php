<?php

namespace Kompo\Auth\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kompo\Auth\Models\Teams\TeamRole;

class TeamRoleSwitcherController extends Controller
{
    public function switch(Request $request)
    {
        $data = $request->validate([
            'team_id' => ['required', 'integer', 'min:1'],
            'role_id' => ['required', 'string', 'max:100'],
        ]);

        $teamRole = TeamRole::getOrCreateForUser($data['team_id'], $request->user()->id, $data['role_id']);

        if (!$teamRole || $teamRole->user_id != $request->user()->id) {
            abort(403);
        }

        if (!$request->user()->switchToTeamRole($teamRole)) {
            abort(403);
        }

        return response()->json([
            'ok' => true,
            'reload' => true,
        ]);
    }
}
