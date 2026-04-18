<?php

namespace Kompo\Auth\Teams\Contracts;

use Illuminate\Support\Collection;

interface TeamRoleAccessDataSourceInterface
{
    public function activeTeamRoles($user, int|string|null $profile): Collection;

    public function teamForAccessCheck(int $teamId);

    public function roleName(string $roleId): string;
}
