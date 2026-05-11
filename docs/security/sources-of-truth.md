# Security Sources of Truth

> One page, one entry point per question. If you find yourself reaching past these to the underlying class, the cache layer, or the DB directly — stop and add the missing helper here instead.

The goal: every question about "what can this user see / do / own?" routes through a single named function. That function is responsible for caching, bypass-context handling, and any future change to how the answer is computed. Direct calls to lower layers are how the codebase grew four ways to ask the same thing.

---

## The questions

### "Does the current user have permission `K`?"

```php
auth()->user()->hasPermission($key, $type, $teamId = null);
```

- **Returns:** `bool`
- **Cached at:** `UserPermissionSet` (Redis O(1) check) → `AuthCacheLayer::$requestCache` → `CachedPermissionResolver`
- **Don't call:** `PermissionResolver::*` directly, `Permission::where(...)` with role joins, `TeamRole::userHas(...)` patterns. All of those bypass the cache stack.

### "Which team IDs does the user have permission `K` in?"

```php
$user->getTeamsIdsWithPermission($key, $type);          // Collection<int>
$user->getTeamsQueryWithPermission($key, $type, $aliasTable); // Builder, for joining
```

- **Cached at:** `AuthCacheLayer` (request)
- **Use the IDs version unless you specifically need a JOIN.** The query version is not cached because it can't be — it returns a builder.
- **Don't call:** `TeamRole` queries directly, role-permission joins by hand.

### "Which records of `ModelClass` does the current user own?"

```php
app(OwnedRecordsResolverInterface::class)->forUser($userId, ModelClass::class);            // int[]
app(OwnedRecordsResolverInterface::class)->isOwnedBy($userId, ModelClass::class, $id);     // bool
```

- **Cached at:** `CachedOwnedRecordsResolver` (request), flushed on save/delete of the model class
- **Resolution precedence** (resolver dispatches):
  1. `HasOwnedRecords::ownedRecordIdsForUser($userId)` if the model implements the contract
  2. `scopeUserOwnedRecords()` if defined (legacy, current auth user only)
  3. `user_id` column auto-detect
- **See:** `docs/security/owned-records.md`

### "Is the request currently bypassing security?"

```php
globalSecurityBypass();        // composite: static request state OR dynamic context
isInBypassContext();           // dynamic-only — set by enterBypassContext / exitBypassContext
staticGlobalSecurityBypass();  // (planned) static-only — auth/session/route/super-admin/config
```

- The composite is what application code should ask. The two parts exist because the dynamic toggle can change mid-request and shouldn't memoize.
- **Don't call:** `auth()->user()->isSuperAdmin()`, `routeIsByPassed()`, `runningInConsole()` separately to reconstruct this. The single helper bundles them.

### "Is permission `K` actually defined / enforced?"

```php
permissionMustBeAuthorized($key);  // bool — true if the permission key exists in the permissions table
```

- **Cached at:** `PermissionDefinitionCache::permissionByKey` (60 s + request memoization in `AuthCacheLayer`)
- **Currently slow per cache miss** (see `docs/plans/2026-05-10-audit-findings.md`). Don't call this in tight loops. It's intended as a one-shot "should I bother applying security at all?" check.

### "Get the Permission row for key `K` (definition, not check)"

```php
\Kompo\Auth\Models\Teams\Permission::findByKey($key);  // Permission|false
```

- **Cached at:** `PermissionDefinitionCache::permissionByKey` (60 s + request)
- **Don't call:** `Permission::where('permission_key', $k)->first()`. That bypasses the cache and on this codebase it costs ~340 ms per call (auth global scope on `Permission` itself + index considerations).

### "Is the current user a super admin?"

```php
auth()->user()->isSuperAdmin();
```

- **Cached at:** `UserContextCache::isSuperAdmin` (request)

### "All ancestor team IDs of team `T`?"

```php
app(TeamHierarchyInterface::class)->getAncestorTeamIds($teamId);     // single
app(TeamHierarchyInterface::class)->getBatchAncestorTeamIds([$ids]); // many
app(TeamHierarchyInterface::class)->getBatchAncestorTeamIdsByTarget([$ids]); // many, indexed per target
$team->getAncestors();  // instance shorthand for the single version
```

- **Cached at:** `CachedTeamHierarchyService` (per-request + Laravel cache)
- **Don't call:** `parentTeam` in a loop. That's an Eloquent `belongsTo`, lazy, one query per hop. There are existing instance methods on `Team` (`getMainParentTeam`, `getAllParents`) that still use the `parentTeam` walk — they predate the hierarchy service and are N+1 traps; prefer the service.
- **Gap (open):** there is no "ancestors up to a given `team_level`" method. Don't roll one with `parentTeam`; extend the CTE in `TeamHierarchyService` instead.

### "All descendant team IDs of team `T` (optionally to depth N)?"

```php
app(TeamHierarchyInterface::class)->getDescendantTeamIds($teamId, $search = '', $maxDepth = null);
$team->getDescendants($maxDepth);
```

- **Cached at:** `CachedTeamHierarchyService` (per-request + Laravel cache)

### "Is team A a descendant of team B?"

```php
$teamB->hasDescendant($teamAId);  // bool
```

---

## Things that look like sources of truth but aren't

| Looks like | Actually is | Use instead |
|---|---|---|
| `Permission::where('permission_key', $k)->first()` | Direct DB read, hits the slow path | `Permission::findByKey($k)` |
| `Permission::all()` | Full table scan, no cache | `PermissionDefinitionCache::permissionsForSection($section)` if scoped, otherwise question whether you need all of them |
| `$team->parentTeam` (in a loop) | Eloquent lazy belongsTo — N+1 trap | `getAncestorTeamIds($team->id)` |
| `$user->teamRoles()->where(...)` | Bypasses every permission cache | `$user->hasPermission(...)` or `getTeamsIdsWithPermission(...)` |
| `globalSecurityBypass()` (in tight loops) | Cheap but not free | Cache the result locally, or guard with `isInBypassContext()` for the dynamic-only case |
| `$model->_bypassSecurity` flag | Internal state of the bypass service | `$bypassService->isSecurityBypassRequiredFast($model, $teamService)` |
| `SecurityMetadataRegistry::for($class)` from app code | Internal — may change shape | Add a typed accessor here if you need it from app code |

---

## Where each cache lives (so you know what `flush*` to call)

| Cache | Class | Scope | Flushed by |
|---|---|---|---|
| User has permission (per request) | `AuthCacheLayer::$requestCache` | request | `flushRequestCache()` (auto on terminate) |
| User permission set (Redis materialized) | `UserPermissionSet` | persistent + TTL | `UserPermissionSet::flushFor($userId)` on role/team changes |
| Permission definition by key | `PermissionDefinitionCache` | 60 s + request | `forgetPermissionKey($key)` or tag flush on permission save |
| Team hierarchy (ancestors/descendants) | `CachedTeamHierarchyService` | request + Laravel cache | tag flush on team move (`PermissionCacheInvalidator`) |
| Team owners per model instance | `CachedTeamSecurityService::$teamOwnersCache` | request | `flushRequestCache()` |
| Field-protection permission per (user, key, model) | `CachedFieldProtectionService::$permissionCache` | request | `flush()` on save/delete listener for that model |
| Owned records per (user, modelClass) | `CachedOwnedRecordsResolver` (planned) | request | `flushFor($modelClass, $userId)` on save/delete |
| Security metadata per model class | `SecurityMetadataRegistry::$cache` | request | `clearAll()` on terminate |
| Global bypass static portion | `GlobalSecurityBypassCache` (planned) | request | `flush()` on Login/Logout/Impersonate |

Anything not in this table is in-line memoization on a class instance — should be visible from the class itself, not documented here.

---

## Team-scope intent macros

Two-layer team scoping — the default reads filter to the current team; multi-team access is explicit:

```php
Model::query()->withMultiTeamAccess()->get();         // expand to every team user has perm in
Model::query()->withCurrentTeamOnly()->get();         // force current-team narrowing (default)
Model::query()->withoutCurrentTeamScope()->get();     // drop the team filter; permission-gated only
```

- **Default**: filtered to `currentTeamId()`. Controlled by `security.read.current_team` (default `'auto'`).
- **Cleared** at request end via the lifecycle terminator (`TeamScopeIntent::reset()`).
- **Caveat**: the intent attaches to the **next** scope evaluation. Chain the macro immediately before the terminal call (`->get()`, `->paginate()`, `->find()`). Clones via paginator counts inherit correctly; nested queries (eager loads) each get their own intent.

## Per-concern config tree

```php
// config/kompo-auth.php
'security' => [
    'read'   => ['enabled' => true, 'current_team' => 'auto', 'multi_team' => 'auto', 'owned_records' => 'auto'],
    'write'  => ['enabled' => true, 'current_team' => 'auto', 'multi_team' => 'opt-in'],
    'delete' => ['enabled' => true, 'current_team' => 'auto', 'multi_team' => 'opt-in'],
    'fields' => ['enabled' => true, 'eager_load_protection' => true, 'gate_inserts' => false],
    'bypass' => ['super_admin' => true, 'console' => true, 'unauthenticated' => false, 'route_opt_out' => true],
    'warn_on_missing_team_contract' => true,
    'warn_on_missing_owned_records_contract' => true,
],
```

Read it via the helper:

```php
kompoAuthSecurityConfig('read.current_team');     // 'auto' | 'opt-in' | 'off'
kompoAuthSecurityConfig('bypass.global', false);  // legacy bypass-security key, mapped
```

The helper reads the tree first, falls back to the legacy flat keys for one cycle. Add to the tree when introducing new behavior; don't add flat keys.

## Fallback chain — loud, never silent

For each gated dimension the package prefers contracts, auto-detects with a warning when reasonable, and fails closed otherwise:

| Dimension | 1. Contract | 2. Fallback | 3. Final |
|---|---|---|---|
| Team scope | `ScopedToTeam::applyTeamSecurityScope` | Auto-detected `team_id` column + `Log::warning` once per class | No filter, no rows on team-restricted models |
| Owned records | `HasOwnedRecords::ownedRecordIdsForUser` | (none — `user_id` no longer auto-detects after Phase 4) + `Log::warning` once per class when `user_id` exists | Empty owned set |
| Permission key | `HasPermissionKey::getPermissionKey` | — | `class_basename` |
| Protected fields | `HasProtectedFields::getProtectionGroups` | — | No protection |
| Op opt-out | `OptsOutOfSecurity::getSkippedSecurityOperations` | — | All ops enabled |
| Strict permissions | `EnforcesStrictPermissions` (marker) | — | Owner-bypass allowed |

To silence the team-scope warning for a model that genuinely doesn't belong to a team, implement the marker:

```php
class GlobalLookupTable extends ModelBase implements NoTeamScope {}
```

`NoTeamScope` is the explicit opt-out — no warning, no auto-detect, no team filter.

## Model-side contracts (Phase 1 surface)

Model declarations can now use explicit contracts instead of the legacy property/method surface. Both work; the contracts are checked first, the legacy reads remain as fallback so existing models are untouched.

| Contract | Replaces | Ready-made trait |
|---|---|---|
| `Kompo\Auth\Contracts\Security\HasPermissionKey` | `$permissionKey`, `getPermissionKey()` | — |
| `Kompo\Auth\Contracts\Security\ScopedToTeam` | `$restrictByTeam`, `$TEAM_ID_COLUMN`, `team_id` auto-detect, `team()` auto-detect, `scopeSecurityForTeams`, `scopeSecurityForTeamByQuery`, `securityRelatedTeamIds` | `BelongsToOneTeam` |
| `Kompo\Auth\Contracts\Security\HasOwnedRecords` | `scopeUserOwnedRecords`, `usersIdsAllowedToManage` | `OwnedByUserIdColumn` |
| `Kompo\Auth\Contracts\Security\HasProtectedFields` | `$sensibleColumns`(Groups), `$sensibleRelationships`(Groups), permission-key overrides, `$discoverSensibleFromDb` | `WithSimpleProtection` (reads legacy props) |
| `Kompo\Auth\Contracts\Security\OptsOutOfSecurity` | `$readSecurityRestrictions = false`, `$saveSecurityRestrictions = false`, `$deleteSecurityRestrictions = false` | — |
| `Kompo\Auth\Contracts\Security\EnforcesStrictPermissions` (marker) | `$disableOwnerBypass`, `$validateOwnedAsWell` | — |
| `Kompo\Auth\Contracts\Security\NoTeamScope` (marker) | n/a — new | Silences the auto-team_id warning and skips team filtering entirely |

Where each contract is read:
- **Permission key resolution** — `SecurityMetadataRegistry::resolvePermissionKey` → registry caches; `HasSecurity::getPermissionKey` and every service reads from there.
- **Protection groups** — `SecurityMetadataRegistry::collectAllProtectionGroups` returns `getProtectionGroups()` directly when the contract is present; legacy 5-source merge runs only as fallback.
- **Team scope** — `ReadSecurityService::applyContractBasedTeamSecurity` (Strategy 0, before the 3 legacy strategies); `TeamSecurityService::calculateTeamOwnersIds` (Strategy 0).
- **Owned records** — `OwnedRecordsResolver::resolve` (Strategy 1).
- **Opt-out** — `SecurityConfigTrait::getCachedSecurityConfig` reads `metadata['skippedOperations']` before the legacy property + config.
- **Strict permissions** — `TeamSecurityService::shouldValidateOwnedRecords` checks `metadata['enforcesStrictPermissions']` first.

## Adding a new question

If you find yourself wanting a new entry point:

1. Is it a question, not a verb? (e.g. "is X owned by Y" yes; "save X" no — that lives on the model.)
2. Does the answer have a stable cache key? Pick the cache layer above that matches the scope.
3. Add the entry point as a method on the existing service interface — never as a new global helper, never as a static on `HasSecurity`. The helper functions in `auth.php` exist for backwards-compat with kompo elements that can't depend-inject.
4. Update this page in the same PR.
