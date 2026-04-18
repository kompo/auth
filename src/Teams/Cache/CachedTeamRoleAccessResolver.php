<?php

namespace Kompo\Auth\Teams\Cache;

use Illuminate\Support\Collection;
use Kompo\Auth\Teams\Contracts\TeamRoleAccessResolverInterface;
use Kompo\Auth\Teams\TeamRoleAccessResolver;

class CachedTeamRoleAccessResolver implements TeamRoleAccessResolverInterface
{
    public function __construct(
        private TeamRoleAccessResolver $inner,
        private UserTeamCache $userTeamCache,
        private AuthCacheLayer $cache,
    ) {}

    public function accessibleTeamIds($user, int|string|null $profile = null, ?string $search = ''): array
    {
        $search = trim((string) $search);

        if ($search !== '') {
            return $this->inner->accessibleTeamIds($user, $profile, $search);
        }

        if ($profile !== null) {
            return array_keys($this->teamIdsWithRoles($user, $profile));
        }

        return $this->userTeamCache->allAccessibleTeams(
            $user->id,
            fn() => $this->inner->accessibleTeamIds($user, $profile)
        );
    }

    public function teamIdsWithRoles($user, int|string|null $profile = 1, ?string $search = ''): array
    {
        $search = trim((string) $search);

        if ($search !== '') {
            return $this->inner->teamIdsWithRoles($user, $profile, $search);
        }

        return $this->userTeamCache->allTeamIdsWithRoles(
            $user->id,
            $profile,
            fn() => $this->inner->teamIdsWithRoles($user, $profile)
        );
    }

    public function hasAccessToTeam($user, int $teamId, ?string $roleId = null, int|string|null $profile = 1): bool
    {
        return $this->userTeamCache->userTeamAccess(
            $user->id,
            $teamId,
            $roleId,
            fn() => $this->inner->hasAccessToTeam($user, $teamId, $roleId, $profile)
        );
    }

    public function resolveForCandidates(
        Collection $candidateTeams,
        $user,
        int|string|null $profile,
        ?string $mode = null,
        $currentTeamRole = null,
        bool $includeCurrentRole = true,
    ): array {
        return $this->inner->resolveForCandidates(
            $candidateTeams,
            $user,
            $profile,
            $mode,
            $currentTeamRole,
            $includeCurrentRole,
        );
    }

    public function activeRoleBranchIndex($user, int|string|null $profile, ?string $mode = null): Collection
    {
        $modeKey = $mode ?: 'all';

        return $this->cache->rememberRequest(
            'teamRoleAccess.activeRoleBranchIndex.' . $user->id . '.' . ($profile ?: 'all') . '.' . $modeKey,
            fn() => $this->inner->activeRoleBranchIndex($user, $profile, $mode)
        );
    }
}
