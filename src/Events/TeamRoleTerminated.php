<?php

namespace Kompo\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kompo\Auth\Models\Teams\TeamRole;

class TeamRoleTerminated
{
    use Dispatchable, SerializesModels;

    public TeamRole $teamRole;

    public function __construct(TeamRole $teamRole)
    {
        $this->teamRole = $teamRole;
    }
}
