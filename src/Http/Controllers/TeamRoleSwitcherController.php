<?php

namespace Kompo\Auth\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Teams\TeamAccessHierarchyBuilder;
use Kompo\Auth\Teams\TeamRoleSwitcherNodeProvider;

class TeamRoleSwitcherController extends Controller
{
    public function bootstrap(Request $request, TeamRoleSwitcherNodeProvider $provider)
    {
        $data = $this->validatedListRequest($request);

        if ($data['search'] !== '') {
            return response()->json($provider->search(
                $request->user(),
                $data['profile'],
                $data['mode'],
                $data['search'],
                $data['limit'],
            ));
        }

        return response()->json($provider->bootstrap(
            $request->user(),
            $data['profile'],
            $data['mode'],
            $data['limit'],
            $data['lookahead'],
        ));
    }

    public function nodes(Request $request, TeamRoleSwitcherNodeProvider $provider)
    {
        $data = $this->validatedListRequest($request, [
            'parent_id' => ['nullable', 'integer', 'min:1', 'exists:teams,id'],
            'cursor' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json($provider->children(
            $request->user(),
            $data['profile'],
            $data['mode'],
            $data['parent_id'] ?? null,
            $data['limit'],
            $data['cursor'] ?? null,
            $data['lookahead'],
        ));
    }

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

    private function validatedListRequest(Request $request, array $extraRules = []): array
    {
        $data = $request->validate(array_merge([
            'mode' => ['nullable', Rule::in([
                TeamAccessHierarchyBuilder::MODE_TEAMS,
                TeamAccessHierarchyBuilder::MODE_COMMITTEES,
            ])],
            'profile' => ['nullable', 'string', 'max:50'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'lookahead' => ['nullable', 'integer', 'min:20', 'max:120'],
            'search' => ['nullable', 'string', 'max:100'],
        ], $extraRules));

        return [
            'mode' => $data['mode'] ?? TeamAccessHierarchyBuilder::MODE_TEAMS,
            'profile' => $data['profile'] ?? currentTeamRole()?->roleRelation?->profile ?? 1,
            'limit' => (int) ($data['limit'] ?? 20),
            'lookahead' => (int) ($data['lookahead'] ?? 80),
            'search' => trim((string) ($data['search'] ?? '')),
        ] + $data;
    }
}
