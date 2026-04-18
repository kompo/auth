# Cache Consolidation & Performance Plan

**Date:** 2026-04-17
**Branch:** `feature/cache-refactor` (extends the 2026-04-14 refactor)
**Scope:** Close the regressions introduced by the decorator refactor, consolidate duplicated data paths across User traits, and restore cross-request sub-caching that was stripped. Tier 3+ items listed as pending.

---

## 1. Why this plan exists

The 2026-04-14 refactor moved caching from `PermissionResolver` into `CachedPermissionResolver` + `AuthCacheLayer`. The decorator pattern is correct, but the implementation:

- **Only wraps 4 public methods.** Internal methods (`getRolePermissions`, `getTeamRolePermissions`, `getTeamRoleAccessibleTeams`, `getUserActiveTeamRoles`, `getAccessibleTeamIds`) had their caching deleted and never re-added at the decorator layer.
- **Left `preloadPermissionData` result in a local array only.** The plan's §7.5 warming to Redis was not implemented.
- **Wipes the entire request cache on every tag invalidation** via `AuthCacheLayer::invalidateTag*` → `flushRequestCache()`. Bulk admin writes rebuild from scratch repeatedly.
- **Duplicates `isSuperAdmin` under two cache keys** (`isSuperAdmin.{uid}` and `user_super_admin.{uid}`) with two tags (`IS_SUPER_ADMIN` vs `USER_SUPER_ADMIN`), so the first caller populates one and the second caller repeats the work.
- **Has five different "get active team roles" paths**, each with its own cache key or none.

The net effect: steady-state hot path is fine, but cold rebuilds (every `user_permissions` TTL expiry, every model save that fires invalidation) do more DB work than master, and cross-user role sharing no longer benefits from role-permission Redis caching.

This plan fixes 9 concrete items. Items 10–17 are pending further design discussion.

---

## 2. Guiding principles

1. **One source of truth per question.** For any question (`is this user super admin?`, `what are this user's active team roles?`, `what teams can this user access?`), there is exactly ONE method to call and ONE cache key it writes.
2. **Sub-caches must survive the outer cache's expiry.** When `user_permissions.{uid}` expires, rebuilding must hit warm `role_permissions.{rid}` / `team_role_permissions.{trid}` / `team_role_access.{trid}` — not re-run batch SQL.
3. **Invalidation must be targeted.** A single TeamRole change must not wipe all users' request caches.
4. **Behavior-preserving.** No change in plan may alter what `userHasPermission`, `getAllAccessibleTeamIds`, or `isSuperAdmin` return. Performance only.
5. **One change per commit**, each commit independently reviewable and revertable.

---

## 3. Concrete work items (to implement)

### Item 1 — Warm Redis inside `preloadPermissionData`

**File:** `src/Teams/PermissionResolver.php:143-181`

**Problem:** `preloadPermissionData` runs two batch DB queries and stores results ONLY in a local `$permissionData` array. On the next `user_permissions` miss for the same user — or any user sharing those roles — the batch queries run again.

**Change:** After each `foreach ($rolePermissions as $roleId => ...)` and `foreach ($teamRolePermissions as $teamRoleId => ...)` loop, write the per-key result into Redis via the injected `AuthCacheLayer`:

```php
// Inject AuthCacheLayer via constructor (pure service still; layer knows how to skip if unavailable)
foreach ($rolePermissions as $roleId => $permissions) {
    $perms = collect($permissions)->pluck('complex_permission_key')->all();
    $permissionData['roles'][$roleId] = $perms;
    $this->cache?->put(
        CacheKeyBuilder::rolePermissions($roleId),
        CacheKeyBuilder::ROLE_PERMISSIONS,
        $perms
    );
}
// same for team_role_permissions
```

The `AuthCacheLayer::put` already exists (line 48). Constructor becomes:

```php
public function __construct(
    private TeamHierarchyInterface $hierarchyService,
    private ?AuthCacheLayer $cache = null,
) {}
```

Service provider updates `singleton(PermissionResolver::class, ...)` to pass the layer.

**Why:** Plan §7.5 that was skipped. Reverses the biggest cold-rebuild regression. Every user sharing a role benefits from the first user's batch query.

**Risk:** Low. Pure additive cache write. Failures swallowed by `AuthCacheLayer::put`'s try/catch.

**Validation:** Add a test that calls `getUserPermissionsOptimized($u1)`, flushes `user_permissions` tag only, then calls `getUserPermissionsOptimized($u2)` where `u2` shares a role with `u1`. Assert no DB query for `permission_role` in the second call.

---

### Item 2 — Wire `PermissionDefinitionCache::accessibleTeamsForTeamRole` into the resolver

**Files:**
- `src/Teams/PermissionResolver.php:219-234` (`getTeamRoleAccessibleTeams`)
- `src/Teams/Cache/PermissionDefinitionCache.php:77-84` (`accessibleTeamsForTeamRole` — already exists, nobody calls it)

**Problem:** Master had a `team_role_access.{trId}` Redis cache that stored a teamRole's precomputed `[team_id, ...descendants, ...siblings]` in one entry. The decorator refactor deleted this. `PermissionDefinitionCache::accessibleTeamsForTeamRole` was written to restore it, but no caller uses it.

**Change:** In `PermissionResolver::getTeamRoleAccessibleTeams`, delegate to the cache:

```php
private function getTeamRoleAccessibleTeams(TeamRole $teamRole)
{
    return $this->definitionCache->accessibleTeamsForTeamRole($teamRole, function () use ($teamRole) {
        $teams = collect([$teamRole->team_id]);
        if ($teamRole->getRoleHierarchyAccessBelow()) {
            $teams = $teams->concat($this->hierarchyService->getDescendantTeamIds($teamRole->team_id));
        }
        if ($teamRole->getRoleHierarchyAccessNeighbors()) {
            $teams = $teams->concat($this->hierarchyService->getSiblingTeamIds($teamRole->team_id));
        }
        return $teams->unique()->values()->all();
    });
}
```

Inject `PermissionDefinitionCache` via constructor. Same DI path as item 1.

Do the same for `TeamRole::getAccessibleTeamsOptimized` (`src/Models/Teams/TeamRole.php:308-327`) — it already uses `accessibleTeamsForTeamRole` correctly, so no change there; this item just adds the resolver side.

**Why:** Per-teamRole rebuild goes from 2 Redis round-trips (descendants + siblings, each via `CachedTeamHierarchyService`) to 1 Redis round-trip (precomputed combined set). For a user with N teamRoles, that's N Redis round-trips saved per `user_permissions` miss.

**Risk:** Low. The `PermissionCacheInvalidator::teamRolesChanged` already flushes `TEAM_ROLE_ACCESS` tag, so invalidation is correct.

---

### Item 3 — Consolidate `getAllAccessibleTeamIds` to a single path

**Files:**
- `src/Models/Teams/HasTeamPermissions.php:126-138` (trait method)
- `src/Teams/PermissionResolver.php:61-72` (`getAllAccessibleTeamsForUser` — needs batched rewrite)
- `src/Teams/Cache/CachedPermissionResolver.php:60-69` (decorator wrapper — already correct)

**Problem:** Two implementations compute the same set:
- `HasTeamPermissions::getAllAccessibleTeamIds` — groups teamRoles by hierarchy pattern, uses `TeamHierarchyRoleProcessor` batch methods.
- `PermissionResolver::getAllAccessibleTeamsForUser` — loops teamRoles, calls per-role `getDescendantTeamIds` + `getSiblingTeamIds` (N×2 queries on cold hierarchy).

The trait version is faster on cold hierarchy; the resolver version is the "official" interface method. Both cache differently.

**Change:**

1. Rewrite `PermissionResolver::getAllAccessibleTeamsForUser` to use the batch grouping strategy from `HasTeamPermissions`:

   ```php
   public function getAllAccessibleTeamsForUser(int $userId)
   {
       $teamRoles = $this->getUserActiveTeamRoles($userId);
       if ($teamRoles->isEmpty()) return [];

       $grouped = $this->groupTeamRolesByHierarchy($teamRoles);
       $result = collect($teamRoles->pluck('team_id')); // direct access

       foreach ($grouped as $type => $group) {
           $teamIds = array_map(fn($tr) => $tr->team_id, $group);
           if ($type === 'below' || $type === 'below_and_neighbors') {
               $result = $result->concat(
                   $this->hierarchyService->getBatchDescendantTeamIdsByRoot($teamIds)->flatten()
               );
           }
           if ($type === 'neighbors' || $type === 'below_and_neighbors') {
               $result = $result->concat(
                   $this->hierarchyService->getBatchSiblingTeamIds($teamIds)
               );
           }
       }
       return $result->unique()->values()->all();
   }
   ```

2. Make `HasTeamPermissions::getAllAccessibleTeamIds` delegate:

   ```php
   public function getAllAccessibleTeamIds($search = null)
   {
       if ($search) {
           return array_keys($this->getAllTeamIdsWithRolesCached(profile: null, search: $search));
       }
       return $this->getPermissionResolver()->getAllAccessibleTeamsForUser($this->id);
   }
   ```

3. `groupTeamRolesByHierarchy` already exists on the trait — promote it to a static helper class `Kompo\Auth\Teams\TeamRoleHierarchyGrouper` used by both sites. Trait keeps a thin wrapper that calls the helper.

**Why:** Single source of truth. `CachedPermissionResolver` already wraps `getAllAccessibleTeamsForUser` with `user_all_accessible_teams.{uid}` cache — we inherit that cache automatically. Removes duplication D1 (your question).

**Risk:** Medium. The two implementations currently return slightly different data shapes in the search-flow (the trait returns `[team_id => [roles]]`; the resolver returns `[team_id, ...]`). The search branch stays on the trait path unchanged. Only the non-search branch consolidates.

---

### Item 4 — Wrap batch hierarchy methods in `CachedTeamHierarchyService`

**File:** `src/Teams/Cache/CachedTeamHierarchyService.php:57-75`

**Problem:** `getBatchAncestorTeamIds`, `getBatchDescendantTeamIdsByRoot`, `getBatchSiblingTeamIds`, `getBatchSiblingTeamIdsBySource` pass through to inner with zero caching. Item 3 above calls them for every `user_permissions` miss.

**Change:** Key each batch by a stable hash of (sorted input IDs, search string). Use `AuthCacheLayer::remember` with the `TEAM_DESCENDANTS` / `TEAM_ANCESTORS` / `TEAM_SIBLINGS` tags (already invalidated on hierarchy change).

```php
public function getBatchAncestorTeamIds(array $teamIds): Collection
{
    $key = 'batch_ancestors.' . md5(json_encode(collect($teamIds)->sort()->values()));
    return $this->cache->remember(
        $key,
        CacheKeyBuilder::TEAM_ANCESTORS,
        fn() => $this->inner->getBatchAncestorTeamIds($teamIds)
    );
}
```

Same treatment for the other three.

**Why:** After item 3, these batch methods are called on the hot rebuild path. They're cheap to cache (output is a collection of IDs, small), and hierarchy changes rarely.

**Risk:** Low. Invalidation already flushes hierarchy tags via `PermissionCacheInvalidator::teamHierarchyChanged`.

**Nuance:** Batch searches with search strings should skip caching (high cardinality, low reuse) — check `if ($search)` and bypass.

---

### Item 5 — Stop wiping the whole request cache on every tag invalidation

**File:** `src/Teams/Cache/AuthCacheLayer.php:75-88`

**Problem:**
```php
public function invalidateTag(string $tag): void {
    $this->cacheFlushTags([$tag]);
    $this->flushRequestCache();  // ← nukes all in-request caches, not just this tag's entries
}
```

Every `TeamRole::saved`, `PermissionRole::saved`, `Team::saved`, etc. calls this. Bulk writes in one request wipe the request cache every time. Permission checks after the save must rebuild from scratch.

**Change:** Track which request-cache keys belong to which tag and only drop matching keys.

```php
private array $requestCache = [];
private array $keysByTag = [];  // tag => [key, key, ...]

public function remember(string $key, string $tag, callable $compute, ?int $ttl = null)
{
    if (array_key_exists($key, $this->requestCache)) return $this->requestCache[$key];
    $value = $this->cacheRememberWithTags(
        CacheKeyBuilder::getTagsForCacheType($tag), $key, $ttl ?? $this->ttlForTag($tag), $compute
    );
    $this->requestCache[$key] = $value;
    $this->keysByTag[$tag][] = $key;
    return $value;
}

public function invalidateTag(string $tag): void
{
    $this->cacheFlushTags([$tag]);
    foreach ($this->keysByTag[$tag] ?? [] as $key) {
        unset($this->requestCache[$key]);
    }
    unset($this->keysByTag[$tag]);
}
```

`invalidateTags(array)` iterates. `invalidateAll` still wipes everything (correct).

Update `rememberRequest` (which doesn't take a tag) to register under a synthetic tag `_request_only` that is only cleared on explicit `flushRequestCache` — that preserves `userHasPermission` in-request cache across unrelated invalidations.

**Why:** Correctness preserved (Redis is still invalidated), but in-request checks that don't depend on the invalidated tag stay cached.

**Risk:** Medium. Must audit: are there request-cache entries whose correctness depends on tags OTHER than the one they were written under? I'm confident: no, because each entry's tag declares its invalidation trigger. The test we'd write: create a `TeamRole`, check permissions for an unrelated user in the same request, assert the unrelated user's `user_permissions` is still in request cache.

---

### Item 6 — Decorator wraps all cache-worthy inner methods

**Files:**
- `src/Teams/Contracts/PermissionResolverInterface.php` (add methods)
- `src/Teams/PermissionResolver.php` (visibility: public for internal methods)
- `src/Teams/Cache/CachedPermissionResolver.php` (wrap)
- `src/Models/Teams/HasTeamPermissions.php` (route callers through interface)

**Problem:** Only 4 public methods are decorated. Internal methods (`getRolePermissions`, `getTeamRolePermissions`, `getTeamRoleAccessibleTeams`, `getUserActiveTeamRoles`, `getAccessibleTeamIds`) are called from within the resolver — but callers OUTSIDE the resolver (the trait, models) don't have a cached path for them. Item 2 partially addresses this via `PermissionDefinitionCache`, but it's ad-hoc.

**Change:**

1. Extend interface:
   ```php
   interface PermissionResolverInterface {
       // existing ...
       public function getUserActiveTeamRoles(int $userId, $teamIds = null): Collection;
       public function getRolePermissions($role): array;
       public function getTeamRolePermissions(TeamRole $teamRole): array;
       public function getTeamRoleAccessibleTeams(TeamRole $teamRole): array;
       public function getAccessibleTeamIds(Collection $targetTeamIds): Collection;
   }
   ```

2. Make these `public` on concrete `PermissionResolver` (currently private).

3. Wrap each in `CachedPermissionResolver` with `AuthCacheLayer::remember` keyed the same way master had:
   - `getRolePermissions($role)` → `role_permissions.{$role->id}` tag `ROLE_PERMISSIONS`
   - `getTeamRolePermissions($tr)` → `team_role_permissions.{$tr->id}` tag `TEAM_ROLE_PERMISSIONS`
   - `getTeamRoleAccessibleTeams($tr)` → `team_role_access.{$tr->id}` tag `TEAM_ROLE_ACCESS` (same as item 2; unify)
   - `getUserActiveTeamRoles($uid, $teamIds)` → `user_active_team_roles.{$uid}.{md5($teamIds)}` tag `USER_ACTIVE_TEAM_ROLES`
   - `getAccessibleTeamIds($targetTeamIds)` → `accessible_teams.{md5($sorted)}` tag `ACCESSIBLE_TEAMS`

4. Inside the INNER `PermissionResolver`, swap direct method calls for decorator-aware calls. Option: pass the decorator as a collaborator (`$this->publicApi`) and route internal calls through it so sub-caches apply even when the outer `user_permissions` cache is cold. This is the core of the plan §9 Phase B that was skipped.

   Concretely: `buildUserPermissionSet` calls `$this->publicApi->getTeamRoleAccessibleTeams(...)` instead of `$this->getTeamRoleAccessibleTeams(...)`. When the public API resolves to the cached decorator, we get the sub-cache. When it's the raw resolver (no decorator bound, e.g., tests), we get the uncached fallback.

5. After item 6 lands, `PermissionDefinitionCache::accessibleTeamsForTeamRole` and `teamRolePermissionKeys` become redundant — removed in a follow-up commit.

**Why:** This is the single biggest correctness+performance improvement. Closes the gap between the refactor's stated design and its actual behavior. Every sub-cache that master relied on comes back, now properly tagged.

**Risk:** Medium-high due to surface area. Mitigated by: no behavior change (same methods return same values), purely additive caching. Each method can ship as its own commit with its own test.

---

### Item 7 — Unify `isSuperAdmin` into one path (D1)

**Files:**
- `src/Teams/CacheKeyBuilder.php:88-91, 174-179` (collapse to one key)
- `src/Teams/Cache/UserContextCache.php:29-37, 57-65`
- `src/Teams/Cache/CachedPermissionResolver.php:145-155`
- `src/Helpers/auth.php:212-226, 378-382`
- `src/Helpers/roles.php:30-34` (delete the dead helper)

**Problem:** Two cache keys (`user_super_admin.{uid}`, `isSuperAdmin.{uid}`) with two tags (`USER_SUPER_ADMIN`, `IS_SUPER_ADMIN`) for the same boolean. Two `isSuperAdmin()` free functions defined (only one wins via `function_exists`). Three call sites compute it.

**Change:**

1. Collapse to one key: drop `CacheKeyBuilder::isSuperAdmin` and `IS_SUPER_ADMIN` tag. Keep `userSuperAdmin` / `USER_SUPER_ADMIN`.
2. `UserContextCache::isSuperAdmin` → use `userSuperAdmin` key.
3. `UserContextCache::putIsSuperAdmin` → write to same key.
4. `CachedPermissionResolver::userIsSuperAdmin` → route through `UserContextCache::isSuperAdmin` (single canonical holder).
5. Delete the duplicate `isSuperAdmin()` function in `src/Helpers/auth.php:378-382`. Keep only `src/Helpers/roles.php:30-34`.
6. `isAppSuperAdmin()` helper stays as the canonical UI-side check; its compute closure calls `authUser()?->isSuperAdmin()` directly (the underlying email check) — no other cached layer.
7. `PermissionCacheInvalidator::clearUserContext` already flushes `IS_SUPER_ADMIN` — update to flush only the new unified tag.

**Why:** Same data, one key. The first caller in a request populates, all subsequent callers reuse. Also fixes the dead `isSuperAdmin` helper.

**Risk:** Low. Backwards-compatible: removes a tag name (no migrations needed, Redis will just GC the stale tag set).

---

### Item 8 — Unify active team roles access (D2)

**Files:**
- `src/Teams/Cache/UserTeamCache.php:29-36` (`activeTeamRoles` — keep as public entry)
- `src/Models/Teams/HasTeamPermissions.php:148-160` (`getActiveTeamRolesOptimized` — already uses UserTeamCache; keep)
- `src/Teams/PermissionResolver.php:103-119` (`getUserActiveTeamRoles` — route through decorator after item 6)
- `src/Teams/TeamRoleSwitcherNodeProvider.php:428-451` (`activeTeamRoles` — point to UserTeamCache)
- Various ad-hoc `$user->teamRoles()->...` call sites: `HasTeamNavigation::resetToValidTeamRole:148`, etc.

**Problem:** Five paths, each with its own cache key. Invalidation has to remember all of them.

**Change:**

1. `UserTeamCache::activeTeamRoles($uid, $roleId, $compute)` is the canonical entry. Keep its current key: `activeTeamRoles.{uid}.{roleId}`.
2. Add a sibling method `UserTeamCache::activeTeamRolesByProfile($uid, $profile, $compute)` using key `activeTeamRoles.{uid}.profile.{profile}` — same tag `USER_ACTIVE_TEAM_ROLES`.
3. `TeamRoleSwitcherNodeProvider::activeTeamRoles` calls the new profile-keyed method. Remove its custom `teamRoleSwitcher.activeTeamRoles.v2.{uid}.{profile}` key.
4. After item 6, `CachedPermissionResolver::getUserActiveTeamRoles` also lives under `user_active_team_roles.{uid}.{teamIdsHash}` with the same tag. Both the profile-keyed and teamIds-keyed variants share one tag so `teamRolesChanged` invalidates both.
5. Ad-hoc `$user->teamRoles()` calls in `resetToValidTeamRole` / `cleanupTeamSetup` / `validateTeamSetup` — these are admin/recovery paths, keep uncached, but document that they MUST clear user cache after mutating.

**Why:** Consolidates to one tag. Invalidation now definitely covers every active-roles cache.

**Risk:** Low. Additive plus one refactor of `TeamRoleSwitcherNodeProvider`.

---

### Item 9 — Synchronous login warming (plan §8 Tier 1)

**Files:**
- `src/Listeners/WarmUserCacheOnLogin.php` (NEW)
- `src/KompoAuthServiceProvider.php:604-614` (register listener)
- `src/Models/Teams/HasTeamPermissions.php:462-494` (`refreshRolesAndPermissionsCache` — already does async warm; keep but trim)

**Problem:** Today, the first authenticated page after login pays full cold-rebuild cost on every permission check. `refreshRolesAndPermissionsCache` only fires `dispatch(...)->afterResponse()`, so the warming runs AFTER the response is sent — but the response itself already rendered with cold caches.

**Change:**

1. Create listener:
   ```php
   class WarmUserCacheOnLogin
   {
       public function __construct(
           private PermissionResolverInterface $resolver,
           private UserContextCache $context,
       ) {}

       public function handle(\Illuminate\Auth\Events\Login $event): void
       {
           $user = $event->user;
           if (!method_exists($user, 'getPermissionResolver')) return;

           // Synchronous (under 50ms typical): warms the current-team fast path.
           $this->context->isSuperAdmin($user->id, fn() => $user->isSuperAdmin());
           $this->resolver->getUserPermissionsOptimized($user->id); // global
           $this->resolver->getAllAccessibleTeamsForUser($user->id);
       }
   }
   ```

2. Register in `loadListeners()`:
   ```php
   Event::listen(\Illuminate\Auth\Events\Login::class, WarmUserCacheOnLogin::class);
   ```

3. `refreshRolesAndPermissionsCache` keeps its `dispatch(...)->afterResponse()` for extra warming (team-switch context, per-role data) — that's fine as async. The synchronous warm is new and covers the first-page render.

**Why:** Plan §8 Tier 1 that was skipped. Single biggest latency cut for authenticated users: the first post-login page.

**Risk:** Low. Login is already a heavy event; adding 3 cache warms (~10-50ms total) is imperceptible. Mitigation: wrap listener body in try/catch so a warm failure never breaks login.

---

## 4. Order & dependencies

```
Item 5 (request-cache fix) ──────┐
                                  ├─▶ Item 6 (decorator wraps internals) ──┐
Item 4 (batch methods cached) ───┘                                          ├─▶ Item 9 (login warm)
                                                                             │
Item 1 (preload → Redis) ─────────────────────────────────────────────────── ┘
Item 2 (team_role_access cache) ──▶ superseded by Item 6; ship first, remove later
Item 3 (getAllAccessibleTeamIds consolidation) ──▶ independent
Item 7 (isSuperAdmin unify) ──▶ independent
Item 8 (active team roles unify) ──▶ after Item 6 (needs interface changes)
```

**Suggested PR sequence:**

1. **PR#1** — Items 1, 5 (smallest, purely additive). Establishes the invariants the next PRs rely on.
2. **PR#2** — Items 2, 4 (cache wiring, low surface area).
3. **PR#3** — Item 6 (the big one). Interface expansion + decorator wrapping + internal routing change. Each method as its own commit.
4. **PR#4** — Items 3, 7, 8 (consolidation passes).
5. **PR#5** — Item 9 (login warming). Ships last because it amplifies the wins from PRs 1-4.

Every PR ships with:
- A before/after DB query count assertion (query-log test) for the specific scenario it targets.
- A manual verification step on one realistic user (many teamRoles) in dev.

---

## 5. Test coverage required

For each item, minimum:

- **Item 1:** Two-user shared-role scenario, assert `permission_role` DB hit count drops to 1 across both users on consecutive `getUserPermissionsOptimized` calls with only `user_permissions` tag flushed between.
- **Item 2:** Single user, flush `user_permissions` only, assert `teams` CTE runs 0 times (hit on `team_role_access.{trId}`).
- **Item 3:** Compare output of `$user->getAllAccessibleTeamIds()` before/after refactor — identical set for a user with each hierarchy variant (direct, below, neighbors, both).
- **Item 4:** Batch hierarchy call twice within a request, assert second call is in-memory (no Redis roundtrip either).
- **Item 5:** Create TeamRole + check unrelated permission in same request, assert unrelated permission cache hit.
- **Item 6:** For each newly-wrapped method, assert Redis key set after first call; assert no DB hit on second call within TTL.
- **Item 7:** `$user->isSuperAdmin()` + `isAppSuperAdmin()` + `$resolver->userIsSuperAdmin($uid)` in same request: exactly one compute call.
- **Item 8:** `$user->getActiveTeamRolesOptimized()` + `TeamRoleSwitcherNodeProvider::bootstrap` in same request: single DB query for team_roles.
- **Item 9:** Measure first-page-after-login query count before/after — expect drop to 0 for permission-related queries.

---

## 6. Pending items (NOT in this plan — future discussion required)

These were in the Tier 2/3/4 proposal but need more design work before concretization:

- **Item 10 — Narrow `PermissionCacheInvalidator`.** `permissionChanged` currently calls `clearAll()` (nukes `permissions-v2` root tag, every user). Should target only users with affected role/permission. Design question: how to cheaply identify affected users without a full `team_roles` scan?

- **Item 11 — Per-user scoped invalidation via Redis SCAN+DEL.** Replace `Cache::tags([user_specific])->flush()` (flushes all users) with `SCAN` + `DEL` on `user_permissions.{uid}.*` pattern. Requires Redis-specific code path; design question: how to abstract this without leaking Redis into `AuthCacheLayer`.

- **Item 12 — Version-counter cache keys.** Replace Laravel's tag-set-membership flush with per-user `user_permissions_version.{uid}` integer increment. Cache keys embed the version. Design question: migration from tag-based to version-based without downtime; which tags stay tag-based (role_definitions, hierarchy).

- **Item 13 — Redis SMEMBERS / bitmap for `userHasPermission`.** Replace PHP `collect()->filter()` loops with `SISMEMBER`. Design question: storage format, key size, tradeoff vs current array + filter speed for small permission sets.

- **Item 14 — CQRS materialization.** Background workers maintain `user_permissions.{uid}` as derived state. Design question: consistency window, event ordering, catch-up on deploy.

- **Item 15 — `user_team_permissions` denormalized table.** Replaces the `getTeamsQueryWithPermissionForUser` heavy EXISTS subqueries. (User said they don't need this one — keep in pending anyway for completeness.)

- **Item 16 — Fragment cache authorization-only UI regions.** Blade @cache blocks keyed by `(uid, permissions_version)`. Design question: cache driver (Varnish/CDN vs Redis vs file), invalidation flow.

- **Item 17 — Client-side permission bitmap.** Ship permissions to Vue on login; server re-validates sensitive endpoints only. Design question: bitmap serialization, refresh strategy on role switch, security boundary definition.

- **Item 18 — Policy compilation / DSL.** Compile permission rules to decision trees. Design question: DSL spec, compilation trigger, performance target.

- **Item 19 — Octane-resident cache.** Keep active user's permissions map in worker memory. Design question: cross-worker invalidation broadcast.

- **Also pending: D3 `canAccessTeam`/`hasAccessToTeam`/`TeamRole::hasAccessToTeam` consolidation** — three methods answer the same question. Delete `canAccessTeam` alias, route `TeamRole::hasAccessToTeam` through the user-level cache when called from a user context.

- **Also pending: D4 `getCurrentPermissionsInAllTeams` vs `getCurrentPermissionKeysInTeams` vs `getUserPermissionsOptimized`** — three entry points for overlapping data. Needs decision on whether to keep all three (with clear docs) or collapse.

---

## 7. Known non-goals

- **No change to `PermissionTypeEnum::hasPermission`** — the iteration cost is real but Tier 3 item 13 targets it separately.
- **No change to query shape** in `getTeamsQueryWithPermissionForUser` — per user instruction.
- **No DB migrations.** All changes are cache-layer and trait-refactor only.
- **No breaking API changes.** All external methods keep their signatures.

---

## 8. Success criteria

After PRs 1-5 land:

1. Cold `user_permissions` rebuild for a user with 10+ teamRoles does ≤2 DB queries (team_roles + permissions batches) when sub-caches are warm. Today: ~10+ queries.
2. Bulk admin writes (e.g., assigning 20 team roles in one request) do not rebuild the permission cache for unrelated users on every save.
3. `isSuperAdmin` resolves from exactly one cache key under one tag.
4. First post-login page render does 0 DB queries for permission checks (listener warmed cache).
5. No duplication of "get active team roles" logic — single tag invalidates every cache variant.
6. All existing tests pass. New tests cover each of items 1-9.

---

## 9. Rollback plan

Each PR is individually revertable. The decorator pattern means adding caching is additive — if a wrap causes a regression, revert only that commit; the inner uncached method still works. No data migrations, no state to roll back.

For item 7 (isSuperAdmin tag collapse), old cache entries under the dead tag eventually expire via TTL — zero-downtime.
