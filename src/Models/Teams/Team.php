<?php

namespace Kompo\Auth\Models\Teams;

use Condoedge\Utils\Models\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kompo\Auth\Contracts\Security\HasOwnedRecords;
use Kompo\Auth\Contracts\Security\ScopedToTeam;
use Kompo\Auth\Facades\UserModel;
use Kompo\Auth\Models\Concerns\Security\OwnedByUserIdColumn;
use Kompo\Auth\Teams\Cache\PermissionCacheInvalidator;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;
use Kompo\Auth\Teams\TeamHierarchyRoleProcessor;

class Team extends Model implements ScopedToTeam, HasOwnedRecords
{
    use OwnedByUserIdColumn;
    use \Condoedge\Utils\Models\Tags\MorphToManyTagsTrait;
    use \Condoedge\Utils\Models\Files\MorphManyFilesTrait;
    use \Condoedge\Utils\Models\ContactInfo\Maps\MorphManyAddresses;
    use \Condoedge\Utils\Models\ContactInfo\Email\MorphManyEmails;
    use \Condoedge\Utils\Models\ContactInfo\Phone\MorphManyPhones;

    public static function booted()
    {
        parent::booted();

        static::saved(function ($team) {
            clearAuthStaticCache();

            $team->clearCache();
        });

        static::deleted(function ($team) {
            clearAuthStaticCache();
            
            $team->clearCache(wasDeleted: true);
        });
    }

    protected function clearCache(bool $wasDeleted = false)
    {
        $invalidator = app(PermissionCacheInvalidator::class);
        $invalidator->teamChanged([$this->id]);
        
        if ($wasDeleted || $this->wasChanged('parent_team_id') || $this->isDirty('parent_team_id')) {
            $invalidator->teamHierarchyChanged(
                array_filter([$this->id, $this->parent_team_id, $this->getOriginal('parent_team_id')])
            );
        }
        
        if ($this->wasRecentlyCreated) {
            $affectedTeamIds = [$this->id];
            
            if ($this->parent_team_id) {
                $affectedTeamIds[] = $this->parent_team_id;
            }
            
            $invalidator->teamCreated($affectedTeamIds);

            $this->addedBy?->clearPermissionCache();
        }
    }

    /* RELATIONS */
    public function owner()
    {
        return $this->belongsTo(UserModel::getClass(), 'user_id')
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
        return $this->belongsToMany(UserModel::getClass(), TeamRole::class)->withPivot('role')->withTimestamps();
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

    /** Cached CTE — avoids the parentTeam belongsTo-per-hop walk. */
    public static function getMainParentTeam($team)
    {
        // CTE orders DESC by depth; first() = deepest ancestor (or self when no parent).
        $rootId = app(TeamHierarchyInterface::class)
            ->getAncestorTeamIds($team->id)
            ->first();

        if (!$rootId || $rootId === $team->id) {
            return $team;
        }

        return static::withoutGlobalScope('authUserHasPermissions')->find($rootId);
    }

    /** Cached CTE. Immediate-parent-first (excludes self) to match the prior contract. */
    public function getAllParents()
    {
        $parentIds = app(TeamHierarchyInterface::class)
            ->getAncestorTeamIds($this->id)
            ->filter(fn($id) => $id !== $this->id)
            ->values();

        if ($parentIds->isEmpty()) {
            return collect();
        }

        $teams = static::withoutGlobalScope('authUserHasPermissions')
            ->whereIn('id', $parentIds)
            ->get()
            ->keyBy('id');

        // CTE is deepest-first; the contract is immediate-parent-first.
        return $parentIds->reverse()->values()->map(fn($id) => $teams->get($id))->filter()->values();
    }

    /**
     * @deprecated Usar TeamHierarchyInterface::getDescendantTeamIds()
     */
    public function getAllChildrenRawSolution($depth = null, $staticExtraSelect = null, $search = '')
    {
        $service = app(TeamHierarchyInterface::class);

        if ($staticExtraSelect && $search) {
            return app(TeamHierarchyRoleProcessor::class)
                ->descendantsWithRole($this->id, $staticExtraSelect[0], $search);
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
        return app(TeamHierarchyInterface::class)->getDescendantTeamIds($this->id, maxDepth: $maxDepth);
    }

    public function getDescendantsWithRole(string $role, string $search = ''): Collection
    {
        return app(TeamHierarchyRoleProcessor::class)->descendantsWithRole($this->id, $role, $search);
    }

    public function hasDescendant(int $teamId): bool
    {
        return app(TeamHierarchyInterface::class)->isDescendant($this->id, $teamId);
    }

    public function getAncestors(): Collection
    {
        return app(TeamHierarchyInterface::class)->getAncestorTeamIds($this->id);
    }

    public function getSiblings(): Collection
    {
        return app(TeamHierarchyInterface::class)->getSiblingTeamIds($this->id);
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
        $isClickeable = config('kompo-auth.breadcrumbs.clickeable-action');

        return _Link($label)->class(currentTeam()->id == $this->id ? 'font-bold' : '')
            ->when($isClickeable, function ($el) {
                return $el->selfPost('switchToTeamRole', ['team_id' => $this->id])
                    ->redirect();
            })->when(!$isClickeable, function ($el) {
                return $el->class('pointer-events-none hover:!text-inherit focus:!shadow-none');
            });
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
        $query->where(function ($q) {
            $q->whereNull('teams.inactive_at')->orWhere('teams.inactive_at', '>', now());
        })->whereNull('teams.deleted_at');
    }

    public function scopeValidForTasks($query)
    {
        return $query;
    }

    public function applyTeamSecurityScope(Builder $query, array $teamIds): void
    {
        $currentTeamId = currentTeamId();
        $query->where(fn($q) => $q
            ->whereIn('teams.id', $teamIds)
            ->orWhere('teams.id', $currentTeamId)
            ->orWhereIn('teams.parent_team_id', $teamIds)
            ->orWhere('parent_team_id', $currentTeamId)
        );
    }

    public function getRelatedTeamIds(): array
    {
        return array_filter([$this->getKey(), $this->parent_team_id]);
    }

    /* ACTIONS */
    public function detachFromTeam($user)
    {
        $teamRoles = TeamRole::withoutGlobalScope('authUserHasPermissions')
            ->where('team_id', $this->id)
            ->where('user_id', $user->id)
            ->get();

        if ($teamRoles->pluck('id')->contains($user->current_team_role_id)) {
            $user->forceFill([
                'current_team_role_id' => null,
            ])->save();
        }

        $teamRoles->each->delete();

        $user->clearPermissionCache();
        app(PermissionCacheInvalidator::class)->userRemovedFromTeam($user, $this);
    }

    /* ELEMENTS */
}
