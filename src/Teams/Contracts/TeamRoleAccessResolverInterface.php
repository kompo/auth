<?php

namespace Kompo\Auth\Teams\Contracts;

use Illuminate\Support\Collection;

interface TeamRoleAccessResolverInterface
{
    public function accessibleTeamIds($user, int|string|null $profile = null, ?string $search = ''): array;

    public function teamIdsWithRoles($user, int|string|null $profile = 1, ?string $search = ''): array;

    public function hasAccessToTeam($user, int $teamId, ?string $roleId = null, int|string|null $profile = 1): bool;

    public function resolveForCandidates(
        Collection $candidateTeams,
        $user,
        int|string|null $profile,
        ?string $mode = null,
        $currentTeamRole = null,
        bool $includeCurrentRole = true,
    ): array;

    public function activeRoleBranchIndex($user, int|string|null $profile, ?string $mode = null): Collection;
}
