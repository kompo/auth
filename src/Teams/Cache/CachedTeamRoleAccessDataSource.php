<?php

namespace Kompo\Auth\Teams\Cache;

use Illuminate\Support\Collection;
use Kompo\Auth\Teams\Contracts\TeamRoleAccessDataSourceInterface;
use Kompo\Auth\Teams\TeamRoleAccessDataSource;

class CachedTeamRoleAccessDataSource implements TeamRoleAccessDataSourceInterface
{
    public function __construct(
        private TeamRoleAccessDataSource $inner,
        private UserTeamCache $userTeamCache,
        private AuthCacheLayer $cache,
    ) {}

    public function activeTeamRoles($user, int|string|null $profile): Collection
    {
        return $this->userTeamCache->activeTeamRolesByProfile(
            $user->id,
            $profile,
            fn() => $this->inner->activeTeamRoles($user, $profile)
        );
    }

    public function teamForAccessCheck(int $teamId)
    {
        return $this->cache->rememberRequest(
            'teamRoleAccess.teamForAccessCheck.' . $teamId,
            fn() => $this->inner->teamForAccessCheck($teamId)
        );
    }

    public function roleName(string $roleId): string
    {
        return $this->cache->rememberRequest(
            'teamRoleAccess.roleName.' . $roleId,
            fn() => $this->inner->roleName($roleId)
        );
    }
}
