<?php

namespace Kompo\Auth\Teams;

use Condoedge\Utils\Contracts\LazyHierarchySourceInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;

class TeamRoleSwitcherHierarchySource implements LazyHierarchySourceInterface
{
    private TeamRoleSwitcherNodeProvider $provider;

    public function __construct(
        TeamRoleSwitcherScopeResolver $scopes,
        TeamRoleSwitcherTeamRepository $teams,
        TeamRoleSwitcherNodeFactory $nodes,
        TeamHierarchyInterface $hierarchy,
        TeamRoleSwitcherScopeCodec $codec,
        protected array $store = [],
    ) {
        $switchUrl = (string) ($store['switchUrl'] ?? route('team-role-switcher.switch'));

        $this->provider = new TeamRoleSwitcherNodeProvider(
            $scopes,
            $teams,
            $nodes,
            $hierarchy,
            $codec,
            $switchUrl,
        );
    }

    public function bootstrap(Request $request): array
    {
        $data = $this->validatedListRequest($request);

        if ($data['search'] !== '') {
            return $this->provider->search(
                $request->user(),
                $data['profile'],
                $data['mode'],
                $data['search'],
                $data['limit'],
            );
        }

        return $this->provider->bootstrap(
            $request->user(),
            $data['profile'],
            $data['mode'],
            $data['limit'],
            $data['lookahead'],
        );
    }

    public function children(Request $request): array
    {
        $data = $this->validatedListRequest($request, [
            'parent_id' => ['nullable', 'string', 'max:255'],
            'cursor' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->provider->children(
            $request->user(),
            $data['profile'],
            $data['mode'],
            $data['parent_id'] ?? null,
            $data['limit'],
            $data['cursor'] ?? null,
            $data['lookahead'],
        );
    }

    protected function validatedListRequest(Request $request, array $extraRules = []): array
    {
        abort_unless($request->user(), 403);

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
