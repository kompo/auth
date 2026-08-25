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

        static::addGlobalScope('validTeam', function ($builder) {
            static::applyValidConditions($builder);
        });

        static::saved(function ($team) {
            clearAuthStaticCache();

            $team->clearCache();
        });

        static::saved(function ($team) {
            if ($team->wasChanged('inactive_at') && $team->inactive_at && !carbon($team->inactive_at)->isFuture()) {
                $team->cascadeDisable();
            }
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
        
        // inactive_at belongs here too: closing a team removes it (and its subtree position)
        // from every hierarchy answer, exactly as deleting it used to. Without this the
        // descendant/ancestor caches keep serving a closed team until they expire.
        if ($wasDeleted
            || $this->wasChanged('parent_team_id') || $this->isDirty('parent_team_id')
            || $this->wasChanged('inactive_at') || $this->isDirty('inactive_at')) {
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

        // validTeam too: a closed ancestor must still resolve, or breadcrumbs break for
        // every open team beneath it.
        return static::withoutGlobalScopes(['authUserHasPermissions', 'validTeam'])->find($rootId);
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

        $teams = static::withoutGlobalScopes(['authUserHasPermissions', 'validTeam'])
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
        $isClickeable = config('kompo-auth.breadcrumbs.clickeable-action')
            && auth()->user()->hasAccessToTeam($this->id);

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
        if ($this->trashed()) {
            return false;
        }

        return !$this->inactive_at || carbon($this->inactive_at)->isFuture();
    }

    /* ACTIONS */

    public function disable($at = null)
    {
        $at = $at ?: now();

        if (!$this->inactive_at || carbon($this->inactive_at)->gt($at)) {
            $this->inactive_at = $at;
        }

        $this->save();

        return $this;
    }

    /**
     * What closing a team ends. Apps override this to terminate whatever hangs off a team;
     * SISC terminates team_roles and person_teams.
     *
     * Called only from the saved hook, and it must never save the team — that is what makes
     * re-entrancy structurally impossible rather than something a flag has to prevent.
     */
    protected function cascadeDisable(): void
    {
        //
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

    /**
     * Single source of truth for "currently open" teams. Shared by the `validTeam` global
     * scope, scopeValid(), scopeActive() and — via validSqlFragment() — the hand-written
     * hierarchy CTEs, so the definition never drifts.
     *
     * Deliberately does NOT filter deleted_at: SoftDeletes owns that, and folding it in here
     * would silently defeat withTrashed().
     *
     * Unlike TeamRole::applyValidConditions the alias-less default is table-QUALIFIED,
     * because this runs inside multi-table joins and inside `(...) as teams` derived tables.
     * Pass '' explicitly for CTE bodies where the table is the only source.
     */
    public static function applyValidConditions($query, ?string $alias = null): void
    {
        $prefix = $alias === null ? 'teams.' : ($alias === '' ? '' : $alias . '.');

        $query->where(fn ($q) => $q->whereNull($prefix . 'inactive_at')
            ->orWhere($prefix . 'inactive_at', '>', now()));
    }

    public static function withClosedAndDeleted()
    {
        return static::withTrashed()->withoutGlobalScope('validTeam');
    }

    /** Raw-SQL twin of applyValidConditions(), for literal SQL where no builder exists. */
    public static function validSqlFragment(string $alias = 't'): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';

        return "({$prefix}inactive_at IS NULL OR {$prefix}inactive_at > NOW())";
    }

    public function scopeValid($query)
    {
        static::applyValidConditions($query);
    }

    /**
     * Retained by name — SISC resolves it as a string via `new ScopeRule('active')`, so
     * renaming it breaks the searchbar filter at runtime rather than at compile time.
     * Now redundant with the global scope, but harmless and still meaningful after an opt-out.
     */
    public function scopeActive($query)
    {
        static::applyValidConditions($query);
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
