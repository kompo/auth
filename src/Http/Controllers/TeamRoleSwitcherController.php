<?php

namespace Kompo\Auth\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TeamRoleSwitcherController extends Controller
{
    public function switch(Request $request)
    {
        $data = $request->validate([
            'team_id' => ['required', 'integer', 'min:1'],
            'role_id' => ['required', 'string', 'max:100'],
        ]);

        if (!$request->user()->switchToTeamWithRole($data['team_id'], $data['role_id'])) {
            abort(403);
        }

        return response()->json([
            'ok' => true,
            'reload' => true,
        ]);
    }
}
