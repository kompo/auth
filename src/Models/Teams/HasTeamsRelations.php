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

    private static int $passiveTeamRoleReadDepth = 0;

    /**
     * Read team state without the missing-role fallback. Resolving a team role is not
     * a pure read: with none usable it switches teams, or logs the user out and ends
     * the request. An observer — analytics, logging, a debug dump — must never cause
     * that as a side effect of looking.
     */
    public static function readingTeamRolePassively(callable $callback)
    {
        self::$passiveTeamRoleReadDepth++;

        try {
            return $callback();
        } finally {
            self::$passiveTeamRoleReadDepth--;
        }
    }

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
        // Deliberately before the attempted-marker: a passive read must leave the state
        // untouched, so a real read later in the same request still gets its fallback.
        if (self::$passiveTeamRoleReadDepth > 0) {
            return null;
        }

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
        return static::redirectAfterLosingTeamRole(
            route('login', ['reason' => self::NO_ACTIVE_ROLE_REASON])
        );
    }

    /** Kept for overrides of noTeamRoleResponse(); the shaping lives in kompoAwareRedirect(). */
    protected static function redirectAfterLosingTeamRole(string $url)
    {
        return kompoAwareRedirect($url);
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
            $currentRole = $this->currentTeamRoleKey();

            $teamRoleWithCurrentRole = $currentRole
                ? $this->activeTeamRoles()->relatedToTeam($teamId)->where('role', $currentRole)->first()
                : null;

            return $teamRoleWithCurrentRole ?? $this->activeTeamRoles()->relatedToTeam($teamId)->first() ??
                TeamRole::getParentHierarchyRole($teamId, $this->id)?->createChildForHierarchy($teamId);
        } finally {
            HasSecurity::exitBypassContext();
        }
    }

    /**
     * A way to get currentTeamRole but without entering in a loop because of manageNullTeamRole
     */
    protected function currentTeamRoleKey()
    {
        if (!$this->current_team_role_id) {
            return null;
        }

        if ($this->relationLoaded('currentTeamRole')) {
            return $this->getRelation('currentTeamRole')?->role;
        }

        return $this->teamRoles()->whereKey($this->current_team_role_id)->value('role');
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
