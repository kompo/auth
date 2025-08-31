<?php

namespace Kompo\Auth\Models\Teams;

use Condoedge\Utils\Models\Model;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Teams\PermissionCacheManager;
use Kompo\Auth\Teams\TeamHierarchyService;

class Team extends Model
{
    use \Condoedge\Utils\Models\Tags\MorphToManyTagsTrait;
    use \Condoedge\Utils\Models\Files\MorphManyFilesTrait;
    use \Condoedge\Utils\Models\ContactInfo\Maps\MorphManyAddresses;
    use \Condoedge\Utils\Models\ContactInfo\Email\MorphManyEmails;
    use \Condoedge\Utils\Models\ContactInfo\Phone\MorphManyPhones;

    public static function booted()
    {
        parent::booted();

        static::saved(function ($team) {
            $team->clearCache();
        });

        static::deleted(function ($team) {
            $team->clearCache();
        });
    }

    protected function clearCache()
    {
        $cacheManager = app(PermissionCacheManager::class);
        
        if ($this->isDirty('parent_team_id')) {
            $cacheManager->invalidateByChange('team_hierarchy_changed', [
                'team_ids' => array_filter([$this->id, $this->parent_team_id, $this->getOriginal('parent_team_id')])
            ]);
        }
        
        if ($this->wasRecentlyCreated) {
            $affectedTeamIds = [$this->id];
            
            if ($this->parent_team_id) {
                $affectedTeamIds[] = $this->parent_team_id;
            }
            
            $cacheManager->invalidateByChange('team_created', [
                'team_ids' => $affectedTeamIds
            ]);

            $this->addedBy?->clearPermissionCache();
            // $store = Cache::getStore();

            // $connection = $store->connection();
            // $redis = \Illuminate\Support\Facades\Redis::connection($connection->getName());
            // $prefix = $store->getPrefix();
            // Cache::tags([self::CACHE_TAG])->forget('team_role_permissions.757767');
            Cache::flushTags(['permissions-v2']);
            // dd($redis->keys("*"));
        }
    }

    /* RELATIONS */
    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id')
            ->withoutGlobalScope('authUserHasPermissions');
    }

    public function parentTeam()
    {
        return $this->belongsTo(config('kompo-auth.team-model-namespace'), 'parent_team_id')
            ->withoutGlobalScope('authUserHasPermissions');
    }

    public function teams()
    {
        return $this->hasMany(config('kompo-auth.team-model-namespace'), 'parent_team_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, TeamRole::class)->withPivot('role')->withTimestamps();
    }

    public function teamRoles()
    {
        return $this->hasMany(TeamRole::class);
    }

    public function authUserTeamRoles()
    {
        return $this->teamRoles()->forAuthUser();
    }

    public function teamInvitations()
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /* CALCULATED FIELDS */
    public function hasUserWithEmail(string $email): int
    {
        return $this->users()->where('email', $email)->count();
    }

    public static function getMainParentTeam($team)
    {
        if (!$team->parentTeam) {
            return $team;
        }

        return static::getMainParentTeam($team->parentTeam);
    }

    public function getAllParents()
    {
        if ($this->parent_team_id) {
            $parentTeam = $this->parentTeam;

            return $parentTeam->getAllParents()->prepend($parentTeam);
        }

        return collect();
    }

    /**
     * @deprecated Usar TeamHierarchyService::getDescendantTeamIds()
     */
    public function getAllChildrenRawSolution($depth = null, $staticExtraSelect = null, $search = '')
    {
        // Mantener por compatibilidad pero marcar como deprecated
        \Log::warning('getAllChildrenRawSolution is deprecated. Use TeamHierarchyService instead.');

        $service = app(TeamHierarchyService::class);

        if ($staticExtraSelect && $search) {
            return $service->getDescendantTeamsWithRole($this->id, $staticExtraSelect[0], $search);
        }

        $descendants = $service->getDescendantTeamIds($this->id, $search, $depth);

        if ($staticExtraSelect) {
            return $descendants->mapWithKeys(fn($id) => [$id => $staticExtraSelect[0]]);
        }

        return $descendants;
    }

    /**
     * Métodos nuevos más semánticamente claros
     */
    public function getDescendants(?int $maxDepth = null): Collection
    {
        return app(TeamHierarchyService::class)->getDescendantTeamIds($this->id, maxDepth: $maxDepth);
    }

    public function getDescendantsWithRole(string $role, string $search = ''): Collection
    {
        return app(TeamHierarchyService::class)->getDescendantTeamsWithRole($this->id, $role, $search);
    }

    public function hasDescendant(int $teamId): bool
    {
        return app(TeamHierarchyService::class)->isDescendant($this->id, $teamId);
    }

    public function getAncestors(): Collection
    {
        return app(TeamHierarchyService::class)->getAncestorTeamIds($this->id);
    }

    public function getSiblings(): Collection
    {
        return app(TeamHierarchyService::class)->getSiblingTeamIds($this->id);
    }

    /**
     * Mejora del método existente hasChildrenIdRawSolution
     */
    public function hasChildrenIdRawSolution($childrenId): bool
    {
        return $this->hasDescendant($childrenId);
    }

    public function rolePill()
    {
        return null;
    }

    public function getTeamSwitcherLink($label = null)
    {
        $label = $label ?: $this->team_name;

        return _Link($label)->class(currentTeam()->id == $this->id ? 'font-bold' : '')
			->selfPost('switchToTeamRole', ['team_id' => $this->id])
			->redirect();
    }

    public function getFullInfoTableElement()
    {
        return _Rows(
            _Html($this->team_name)->class('font-semibold'),
            _Html($this->getParentTeams()->pluck('team_name')->implode('<br>'))->class('text-sm text-gray-500'),
        );
    }

    public function isActive()
    {
        return !$this->inactive_at || $this->inactive_at > now() && !$this->deleted_at;
    }

    public function getNotificationsEmailAddress()
    {
        return $this->owner?->email;
    }

    /* SCOPES */
    public function scopeForParentTeam($query, $teamIdOrIds)
    {
        if (isWhereCondition($teamIdOrIds)) {
            $query->where('parent_team_id', $teamIdOrIds);
        } else {
            $query->whereIn('parent_team_id', $teamIdOrIds);
        }
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('team_name', 'LIKE', wildcardSpace($search));
    }

    public function scopeActive($query)
    {
        $query; // This query was removed for performance reasons, just keeping it here for compatibility
    }

    public function scopeValidForTasks($query)
    {
        return $query;
    }

    public function scopeSecurityForTeams($query, $teamIds)
    {
        $query->where(fn($q) => $q->whereIn('teams.id', $teamIds)->orWhere('teams.id', currentTeamId())->orWhereIn('teams.parent_team_id', $teamIds)->orWhere('parent_team_id', currentTeamId()));
    }

    /* ACTIONS */
    public function detachFromTeam($user)
    {
        //TODO: refactor for current_team_role_id
        if ($user->current_team_id === $this->id) {
            $user->forceFill([
                'current_team_id' => null,
            ])->save();
        }

        $this->users()->detach($this->user);

        if (!$this->user->teams()->count()) {
            // code...
        }
    }

    /* ELEMENTS */
}
