<?php

namespace Kompo\Auth\Models\Teams;

use Illuminate\Http\Exceptions\HttpResponseException;
use Kompo\Auth\Models\Plugins\HasSecurity;
use Kompo\Auth\Models\Teams\TeamRole;

/**
 * Handles only Eloquent relationships
 */
trait HasTeamsRelations
{
    /** Passed to the login screen so it can explain why the user was logged out. */
    public const NO_ACTIVE_ROLE_REASON = 'no-active-role';

    private static array $manageNullCurrentTeamRoleAttempted = [];

    /* RELATIONS */
    public function currentTeamRole()
    {
        HasSecurity::enterBypassContext();

        try {
            // Auto-select first team role if none is set
            if ($this->exists && !$this->current_team_role_id) {
                $this->manageNullCurrentTeamRole();
            }

            $res = $this->belongsTo(TeamRole::class, 'current_team_role_id')
                ->withoutGlobalScope('authUserHasPermissions');

            $validityCacheKey = ($this->getKey() ?? spl_object_id($this)) . ':' . ($this->current_team_role_id ?? 0);
            $this->memoize('currentTeamRoleValidity:' . $validityCacheKey, function () use ($res) {
                $isValid = (clone $res)->has('roleRelation')->has('team')->exists();

                if (!$isValid) {
                    $this->manageNullCurrentTeamRole();
                }
            });

            return $res;
        } finally {
            HasSecurity::exitBypassContext();
        }
    }

    protected function manageNullCurrentTeamRole()
    {
        $userKey = $this->getKey() ?? spl_object_id($this);
        if (isset(self::$manageNullCurrentTeamRoleAttempted[$userKey])) {
            return null;
        }

        self::$manageNullCurrentTeamRoleAttempted[$userKey] = true;

        if (!$this->switchToFirstTeamRole()) {
            return $this->nullTeamRoleAction();
        }
    }

    protected function nullTeamRoleAction()
    {
        if (auth()->id() !== $this->id) {
            // If the user is not the owner of the account, just return null. Because it's not related
            // to current session
            return null;
        }

        // Impersonation: drop back to the real admin, who keeps their own roles.
        if (auth()->user()->isImpersonated()) {
            auth()->user()->leaveImpersonation();

            abort(403, __('auth-you-dont-have-access-to-any-team'));
        }

        auth()->logout();

        throw new HttpResponseException($this->noTeamRoleResponse());
    }

    /**
     * Where a user with no usable team role lands. Overridable so an app can point
     * at its own entry points (registration, support) from the login screen.
     */
    protected function noTeamRoleResponse()
    {
        $url = route('login', ['reason' => self::NO_ACTIVE_ROLE_REASON]);

        // A Kompo XHR would follow a 302 and swallow the login page as its payload.
        return request()->expectsJson()
            ? response()->kompoRedirect($url)
            : redirect()->to($url);
    }

    public function ownedTeams()
    {
        return $this->hasMany(config('kompo-auth.team-model-namespace'));
    }

    public function teams()
    {
        return $this->belongsToMany(config('kompo-auth.team-model-namespace'), TeamRole::class)->withPivot('role');
    }

    public function teamRoles()
    {
        return $this->hasMany(TeamRole::class)
            ->when(auth()->id() == $this->id, function ($q) {
                $q->withoutGlobalScope('authUserHasPermissions');
            });
    }

    public function activeTeamRoles()
    {
        return $this->teamRoles()->valid()->whereHas('team', fn($q) => $q->active()
            ->when(auth()->id() == $this->id, function ($q) {
                $q->withoutGlobalScope('authUserHasPermissions');
            })
        );
    }    
    
    /* CALCULATED FIELDS - Basic getters only */
    public function getRelatedTeamRoles($teamId = null)
    {
        return $this->activeTeamRoles()->relatedToTeam($teamId)->get();
    }

    public function getFirstTeamRole($teamId = null)
    {
        HasSecurity::enterBypassContext();
        try {
            // First trying to use the current role but in the another team
            $teamRoleWithCurrentRole = $this->currentTeamRole ? $this->activeTeamRoles()->relatedToTeam($teamId)->where('role', $this->currentTeamRole->role)->first() : null;

            return $teamRoleWithCurrentRole ?? $this->activeTeamRoles()->relatedToTeam($teamId)->first() ??
                TeamRole::getParentHierarchyRole($teamId, $this->id)?->createChildForHierarchy($teamId);
        } finally {
            HasSecurity::exitBypassContext();
        }
    }

    public function getLatestTeamRole($teamId = null)
    {
        HasSecurity::enterBypassContext();
        try {
            return $this->activeTeamRoles()->relatedToTeam($teamId)->latest()->first();
        } finally {
            HasSecurity::exitBypassContext();
        }
    }

    public function isOwnTeamRole($teamRole)
    {
        return $this->id == $teamRole->user_id;
    }

    public function ownsTeam($team)
    {
        if (is_null($team)) {
            return false;
        }
        return $this->id == $team->user_id;
    }

    public static function flushCurrentTeamRoleState(): void
    {
        self::$manageNullCurrentTeamRoleAttempted = [];
    }
}
