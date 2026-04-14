# Cache Consistency & Architecture Proposal

**Date:** 2026-04-14
**Scope:** Full cache system audit, bug fixes, performance, and layered architecture

---

## Table of Contents

1. [State Change Map](#1-state-change-map)
2. [Cache Inventory](#2-cache-inventory)
3. [Bug Fixes (Priority 1)](#3-bug-fixes-priority-1)
4. [Cache Tag Unification (Priority 2)](#4-cache-tag-unification-priority-2)
5. [TTL Centralization (Priority 3)](#5-ttl-centralization-priority-3)
6. [Request Lifecycle Safety (Priority 4)](#6-request-lifecycle-safety-priority-4)
7. [Performance Optimizations (Priority 5)](#7-performance-optimizations-priority-5)
8. [Cache Warming Strategy (Priority 6)](#8-cache-warming-strategy-priority-6)
9. [Layered Architecture (Priority 7)](#9-layered-architecture-priority-7)
10. [File-by-File Change Map](#10-file-by-file-change-map)

---

## 1. State Change Map

Every operation that mutates auth-related data and its current cache invalidation:

```
STATE CHANGE                              INVALIDATION                               STATUS
─────────────────────────────────────────────────────────────────────────────────────────────

TEAM MODEL
  Team saved                              clearAuthStaticCache + clearCache()         OK
  Team deleted                            clearAuthStaticCache + clearCache()         OK
  Team parent_team_id changed             team_hierarchy_changed                      OK
  Team.detachFromTeam(user)               NOTHING (pivot detach, no events)           BROKEN

TEAM ROLE MODEL
  TeamRole saved                          team_role_changed {user_ids}                OK
  TeamRole deleted                        team_role_changed {user_ids}                OK
  TeamRole.terminate/suspend/unsuspend    Triggers saved() hook                       OK
  TeamRole.createChildForHierarchy        Triggers saved() hook                       OK

ROLE MODEL
  Role saved                              role_permissions_changed + forget('roles')  PARTIAL
  Role deleted                            role_permissions_changed + forget('roles')  PARTIAL
  Role.createOrUpdatePermission (attach)  NOTHING (pivot op, no events)               BROKEN
  Role.createOrUpdatePermission (update)  NOTHING (pivot op, no events)               BROKEN

PERMISSION-ROLE PIVOT
  PermissionRole saved                    role_permissions_changed {role_ids}          OK
  PermissionRole deleted                  role_permissions_changed {role_ids}          OK
  $role->permissions()->detach()          PermissionRole events DON'T fire,           BROKEN
                                          only flushTags(['permissions']) (wrong tag)

PERMISSION-TEAM-ROLE PIVOT
  PermissionTeamRole saved                team_role_changed {team_role_ids}            BROKEN (wrong key)
  PermissionTeamRole deleted              team_role_changed {team_role_ids}            BROKEN (wrong key)

PERMISSION MODEL
  Permission saved                        NOTHING (no booted hook)                     BROKEN
  Permission deleted                      NOTHING (no booted hook)                     BROKEN
  EditPermissionInfo.afterSave            forget('permissions_of_section_X') only      PARTIAL

USER MODEL
  User updated                            clearPermissionCache()                       OK
  User deleted                            clearPermissionCache()                       OK
  User.switchToTeamRole                   refreshRolesAndPermissionsCache()            OK
  User.givePermissionTo                   PermissionTeamRole + explicit clear          OK

ROLE FORM/MANAGEMENT
  RoleForm.afterSave                      Cache::flush() (NUCLEAR)                     OVERKILL
  changeRolePermissionSection             flushTags(['permissions']) (wrong tag)        BROKEN
  changeRolePermission                    flushTags(['permissions']) (wrong tag)        BROKEN

ROLE LIST HELPERS
  getRoles()                              Only via Cache::forget('roles')               BROKEN
  getRolesOrderedByRelevance()            Only via Cache::forget('roles_by_relevance')  NEVER CLEARED
```

---

## 2. Cache Inventory

### Persistent Cache (Redis)

All entries should use the `permissions-v2` root tag plus a specific sub-tag.

```
CACHE KEY PATTERN                                  TAG(S) USED                STATUS
──────────────────────────────────────────────────────────────────────────────────────

PERMISSION RESOLVER (src/Teams/PermissionResolver.php)
  user_permissions.{uid}.{teamKey}                 [pv2, user_permissions]    OK
  user_teams_with_permission.{uid}.{key}.{type}    [pv2, user_teams_w_perm]  OK
  user_all_accessible_teams.{uid}                  [pv2, user_all_teams]     OK
  accessible_teams.{uid}.{tids}                    [pv2, accessible_teams]   OK
  role_permissions.{roleId}                        [pv2, role_permissions]   OK
  team_role_permissions.{trId}                     [pv2, tr_permissions]     OK
  team_role_access.{trId}                          [pv2, tr_access]         OK
  user_super_admin.{uid}                           [pv2, user_super_admin]  OK

TEAM HIERARCHY SERVICE (src/Teams/TeamHierarchyService.php)
  descendants.{tid}.{depth}{search}                [pv2, team_descendants]   OK
  descendants_with_role.{tid}.{role}.{search}      [pv2, team_desc_w_role]  OK
  is_descendant.{pid}.{cid}                        [pv2, team_is_desc]     OK
  ancestors.{tid}                                  [pv2, team_ancestors]    OK
  siblings.{tid}.{search}                          [pv2, team_siblings]     OK

HELPERS (src/Helpers/auth.php)
  currentTeamRole.{uid}                            [pv2, current_team_role] OK
  currentTeam.{uid}                                [pv2, current_team]      OK
  isSuperAdmin.{uid}                               [pv2, is_super_admin]    OK

HAS TEAM PERMISSIONS TRAIT (src/Models/Teams/HasTeamPermissions.php)
  user_team_access.{uid}.{tid}.{role}              [pv2, user_team_access]  OK
  user_all_accessible_teams.{uid}                  [pv2]                    OK (dup key)
  activeTeamRoles.{uid}.{roleId}                   [pv2]                    OK
  allTeamIdsWithRoles.{uid}.{profile}              [pv2]                    OK
  countTeamIdsWithRolesPairs.{uid}.{profile}        [pv2]                    OK
  currentPermissionsInAllTeams{uid}                [pv2]                    OK
  currentPermissionKeys{uid}|{tids}                [pv2]                    OK

TEAM ROLE MODEL (src/Models/Teams/TeamRole.php)
  teamRolePermissions{trId}                        ['permissions']           WRONG TAG
  team_role_accessible.{trId}                      [pv2]                    OK

PERMISSION MODEL (src/Models/Teams/Permission.php)
  permission_{key}                                 NO TAGS                   BROKEN

PERMISSION SECTION MODEL (src/Models/Teams/PermissionSection.php)
  permissions_of_section_{id}                      ['permissions']           WRONG TAG

ROLE HELPERS (src/Helpers/roles.php)
  roles_all                                        NO TAGS                   BROKEN
  roles_by_relevance                               NO TAGS                   BROKEN

CACHE MANAGER (src/Teams/PermissionCacheManager.php)
  critical_users_list                              NO TAGS                   MINOR
  permission_cache_stats                           NO TAGS                   MINOR

DB HELPER (src/Helpers/db.php)
  hasColumn_{table}_{column}                       NO TAGS                   ACCEPTABLE
```

### Request-Level Caches (7 independent systems)

```
CACHE                                              CLEARED AT REQUEST END?
────────────────────────────────────────────────────────────────────────────
PermissionResolver->requestCache[]                 YES (via HasSecurity terminating)
HasTeamPermissions->permissionRequestCache[]       NO (instance, dies with FPM)
UnifiedCacheService->requestCache[]                NO (singleton, NOT USED)
PermissionCacheService::$batchPermissionCache      YES (via HasSecurity terminating)
PermissionCacheService::$permissionCheckCache      YES (via HasSecurity terminating)
SecurityMetadataRegistry::$cache                   YES (via HasSecurity terminating)
SecurityConfigTrait::$securityConfigCache           NO (never cleared)
FieldProtectionService::$fieldProtectionInProgress YES (via HasSecurity terminating)
FieldProtectionService::$blockedRelationshipsRegistry YES (via HasSecurity terminating)
Element macros static $permissionCache              NO (closure-scoped, survives Octane)
```

---

## 3. Bug Fixes (Priority 1)

### Fix 3.1: PermissionTeamRole sends wrong invalidation key

**File:** `src/Models/Teams/PermissionTeamRole.php:25-28`
**Problem:** Sends `team_role_ids` but `PermissionCacheManager` reads `user_ids` (always empty).
**Result:** Direct team-role permission changes never invalidate cache.

```php
// CURRENT (broken)
protected function clearCache()
{
    app(PermissionCacheManager::class)->invalidateByChange('team_role_changed', [
        'team_role_ids' => [$this->team_role_id]
    ]);
}

// FIX
protected function clearCache()
{
    $teamRole = \Kompo\Auth\Models\Teams\TeamRole::withoutGlobalScope('authUserHasPermissions')
        ->find($this->team_role_id);

    app(PermissionCacheManager::class)->invalidateByChange('team_role_changed', [
        'user_ids' => $teamRole ? [$teamRole->user_id] : []
    ]);
}
```

### Fix 3.2: Role.createOrUpdatePermission has no cache invalidation

**File:** `src/Models/Teams/Roles/Role.php:160-169`
**Problem:** `attach()` and `updateExistingPivot()` are pivot operations that don't fire PermissionRole model events.
**Result:** When the permission management page changes a role's permissions via this method, cache is stale.

```php
// FIX: add invalidation at end of method
public function createOrUpdatePermission($permissionId, $value)
{
    $permission = $this->permissions()->where('permissions.id', $permissionId)->first();

    if (!$permission) {
        $this->permissions()->attach($permissionId, [
            'permission_type' => $value,
            'added_by' => auth()->id(),
            'modified_by' => auth()->id(),
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    } else {
        $this->permissions()->updateExistingPivot($permissionId, [
            'permission_type' => $value,
            'modified_by' => auth()->id(),
            'updated_at' => now(),
        ]);
    }

    // Pivot ops don't fire PermissionRole events - invalidate explicitly
    app(PermissionCacheManager::class)->invalidateByChange('role_permissions_changed', [
        'role_ids' => [$this->id]
    ]);
}
```

### Fix 3.3: RoleRequestsUtils uses wrong cache tag

**File:** `src/Teams/Roles/RoleRequestsUtils.php:37,60`
**Problem:** Calls `\Cache::flushTags(['permissions'], true)` - old tag. Also `detach()` doesn't fire PermissionRole events.
**Result:** Permission changes via the role editor don't invalidate the `permissions-v2` cache.

```php
// FIX: replace both lines with proper invalidation
// In changeRolePermissionSection(), after the foreach/detach block:
app(\Kompo\Auth\Teams\PermissionCacheManager::class)->invalidateByChange('role_permissions_changed', [
    'role_ids' => [$role->id]
]);
// Remove: \Cache::flushTags(['permissions'], true);

// Same fix in changeRolePermission()
```

### Fix 3.4: RoleForm.afterSave uses Cache::flush() (nuclear)

**File:** `src/Teams/Roles/RoleForm.php:39-45`
**Problem:** Destroys the entire cache store including unrelated caches.

```php
// FIX: targeted invalidation
public function afterSave()
{
    app(\Kompo\Auth\Teams\PermissionCacheManager::class)->invalidateByChange('role_permissions_changed', [
        'role_ids' => [$this->model->id]
    ]);
}
```

### Fix 3.5: Team.detachFromTeam has no cache invalidation

**File:** `src/Models/Teams/Team.php:253-267`
**Problem:** `$this->users()->detach()` is a BelongsToMany pivot operation. No TeamRole events fire. User's permission cache is stale.

```php
// FIX: add cache clear after detach
public function detachFromTeam($user)
{
    if ($user->current_team_id === $this->id) {
        $user->forceFill([
            'current_team_id' => null,
        ])->save();
    }

    $this->users()->detach($user);

    $user->clearPermissionCache();
}
```

### Fix 3.6: Permission model has no cache invalidation on save

**File:** `src/Models/Teams/Permission.php`
**Problem:** No booted() hook. When permissions are renamed/deleted, caches serve stale data.

```php
// FIX: add booted hook
public static function booted()
{
    parent::booted();

    static::saved(function ($permission) {
        \Cache::forget("permission_{$permission->permission_key}");

        app(\Kompo\Auth\Teams\PermissionCacheManager::class)->invalidateByChange('permission_updated', [
            'permission_keys' => [$permission->permission_key]
        ]);
    });

    static::deleted(function ($permission) {
        \Cache::forget("permission_{$permission->permission_key}");

        app(\Kompo\Auth\Teams\PermissionCacheManager::class)->invalidateByChange('permission_updated', [
            'permission_keys' => [$permission->permission_key]
        ]);
    });
}
```

### Fix 3.7: Role list helper caches never properly invalidated

**File:** `src/Helpers/roles.php:48-63` and `src/Models/Teams/Roles/Role.php:50`
**Problem:** `getRoles()` caches with key `roles_all`, `getRolesOrderedByRelevance()` with `roles_by_relevance`. But `Role::clearCache()` only forgets key `roles` (none of those). The old `RoleForm::afterSave()` with its nuclear `Cache::flush()` was accidentally masking this.

```php
// FIX in src/Helpers/roles.php: use tagged cache
function getRoles()
{
    return \Cache::rememberWithTags(['permissions-v2', 'role_definitions'], 'roles_all', 3600, function() {
        return \Kompo\Auth\Models\Teams\Roles\Role::orderBy('name')->get();
    });
}

function getRolesOrderedByRelevance()
{
    return \Cache::rememberWithTags(['permissions-v2', 'role_definitions'], 'roles_by_relevance', 10800, function() {
        return \Kompo\Auth\Models\Teams\Roles\Role::query()
            ->withCount('teamRoles')
            ->orderByDesc('team_roles_count')
            ->get();
    });
}

// FIX in Role.php clearCache(): flush the tag instead of forget individual key
protected function clearCache()
{
    app(PermissionCacheManager::class)->invalidateByChange('role_permissions_changed', [
        'role_ids' => [$this->id]
    ]);

    \Cache::flushTags(['role_definitions']);
}
```

### Fix 3.8: EditPermissionInfo only forgets one key

**File:** `src/Teams/Roles/EditPermissionInfo.php:25-28`
**Problem:** Only forgets the section-specific key, doesn't invalidate broader permission caches.

```php
// FIX
public function afterSave()
{
    \Cache::forget('permissions_of_section_' . $this->model->permission_section_id);

    app(\Kompo\Auth\Teams\PermissionCacheManager::class)->invalidateByChange('permission_updated', [
        'permission_keys' => [$this->model->permission_key]
    ]);
}
```

---

## 4. Cache Tag Unification (Priority 2)

### Problem

Three tag systems coexist:
- `permissions-v2` + specific sub-tags (new system, used by PermissionResolver/Hierarchy)
- `permissions` (old system, used by TeamRole.getAllPermissionsKeys, PermissionSection.getPermissions)
- No tags (Permission.findByKey, getRoles, critical_users_list)

### Changes

| File | Current Tag | New Tag |
|---|---|---|
| `TeamRole.php:141` (getAllPermissionsKeys) | `['permissions']` | `['permissions-v2', 'team_role_permissions']` |
| `PermissionSection.php:25` (getPermissions) | `['permissions']` | `['permissions-v2', 'permission_definitions']` |
| `Permission.php:47` (findByKey) | none | `['permissions-v2', 'permission_definitions']` |
| `roles.php:50` (getRoles) | none | `['permissions-v2', 'role_definitions']` |
| `roles.php:57` (getRolesOrderedByRelevance) | none | `['permissions-v2', 'role_definitions']` |

After this, all auth-related cache uses `permissions-v2` as root tag, and `PermissionCacheManager::clearAllCache()` (which flushes `permissions-v2`) covers everything.

---

## 5. TTL Centralization (Priority 3)

### Problem

Config defines `kompo-auth.cache.ttl = 900` but nothing reads it. All TTLs are hardcoded.

### Solution

Add sub-keys to config, read them in services:

```php
// config/kompo-auth.php
'cache' => [
    'ttl' => 900,                    // Default for permission data
    'hierarchy_ttl' => 3600,         // Team hierarchy (stable data)
    'role_switcher_ttl' => 180,      // Team/role listing (changes often)
    'super_admin_ttl' => 3600,       // Super admin status (very stable)
    'permission_lookup_ttl' => 60,   // Permission::findByKey (short, high throughput)
    'role_list_ttl' => 3600,         // getRoles() cached list
    'tags_enabled' => true,
    'warm_critical_users' => true,
    'max_cache_size_mb' => 100,
],
```

Then replace hardcoded values:

| File:Line | Current | New |
|---|---|---|
| `PermissionResolver.php:21` | `const CACHE_TTL = 900` | `config('kompo-auth.cache.ttl', 900)` |
| `TeamHierarchyService.php:16` | `const CACHE_TTL = 3600` | `config('kompo-auth.cache.hierarchy_ttl', 3600)` |
| `HasTeamPermissions.php:193` | `180` | `config('kompo-auth.cache.role_switcher_ttl', 180)` |
| `auth.php:171,195` | `900` | `config('kompo-auth.cache.ttl', 900)` |
| `auth.php:234` | `3600` | `config('kompo-auth.cache.super_admin_ttl', 3600)` |
| `Permission.php:47` | `30` | `config('kompo-auth.cache.permission_lookup_ttl', 60)` |

---

## 6. Request Lifecycle Safety (Priority 4)

### Problem

`HasSecurity::registerRequestCleanup()` already clears some statics via `app()->terminating()`. But it only runs when at least one model with `HasSecurity` boots. And it misses:
- `SecurityConfigTrait::$securityConfigCache`
- Element macros `static $permissionCache` (closure-scoped, impossible to clear externally)
- `PermissionResolver->requestCache[]` (instance, not static)

### Solution

Move cleanup to the service provider so it always runs:

```php
// KompoAuthServiceProvider.php - in boot()
$this->app->terminating(function () {
    // Already covered by HasSecurity but ensure they run even without model boots:
    SecurityMetadataRegistry::clearAll();
    FieldProtectionService::clearTracking();
    SecurityBypassService::clearTracking();
    PermissionCacheService::clearAllCaches();

    // Not currently cleared:
    SecurityConfigTrait::clearCache();  // needs new static method

    // Instance-level cleanup for singletons:
    if ($this->app->resolved(PermissionResolver::class)) {
        $this->app->make(PermissionResolver::class)->clearRequestCache();
    }
});
```

For the element macros closure-scoped `static $permissionCache`: these only matter in Octane. Refactor from closure-scoped static to a resettable static on a class:

```php
// New: src/Support/ElementPermissionCache.php
class ElementPermissionCache
{
    protected static array $cache = [];

    public static function get(string $key): ?bool
    {
        return static::$cache[$key] ?? null;
    }

    public static function set(string $key, bool $value): void
    {
        static::$cache[$key] = $value;
    }

    public static function clear(): void
    {
        static::$cache = [];
    }
}
```

Then in `auth.php` macros replace `static $permissionCache = []` with `ElementPermissionCache::get/set`. Add `ElementPermissionCache::clear()` to the terminating callback.

---

## 7. Performance Optimizations (Priority 5)

### 7.1: Batch ancestor/sibling resolution

**File:** `src/Teams/PermissionResolver.php:199-210`
**Problem:** N+1 per target team on cold cache.

```php
// CURRENT
foreach ($targetTeamIds as $teamId) {
    $ancestors = $this->hierarchyService->getAncestorTeamIds($teamId);   // query per team
    $siblings = $this->hierarchyService->getSiblingTeamIds($teamId);     // query per team
}

// FIX: add batch methods to TeamHierarchyService
$allAncestors = $this->hierarchyService->getBatchAncestorTeamIds($targetTeamIds->all());
$allSiblings = $this->hierarchyService->getBatchSiblingTeamIds($targetTeamIds->all());
$accessibleTeams = $accessibleTeams->concat($allAncestors)->concat($allSiblings);
```

New method in `TeamHierarchyService`:
```php
public function getBatchAncestorTeamIds(array $teamIds): Collection
{
    if (empty($teamIds)) return collect();

    $placeholders = str_repeat('?,', count($teamIds) - 1) . '?';
    $sql = "
        WITH RECURSIVE team_ancestors AS (
            SELECT id, parent_team_id, 0 as depth
            FROM teams WHERE id IN ({$placeholders})
            UNION ALL
            SELECT t.id, t.parent_team_id, ta.depth + 1
            FROM teams t
            INNER JOIN team_ancestors ta ON t.id = ta.parent_team_id
            WHERE ta.depth < 50 AND t.deleted_at IS NULL
        )
        SELECT DISTINCT id FROM team_ancestors
    ";

    return collect(DB::select($sql, $teamIds))->pluck('id');
}
```

### 7.2: Replace count() > 0 with exists()

| File:Line | Current | Fix |
|---|---|---|
| `Role.php:113` | `$this->teamRoles()->count() > 0` | `$this->teamRoles()->exists()` |
| `Role.php:153` | `$this->teamRoles()->count() > 0` | `$this->teamRoles()->exists()` |
| `PermissionSection.php:36` | `->count() == $this->permissions()->count()` | Consider caching or single query |
| `PermissionSection.php:57` | same pattern | same fix |

### 7.3: Extend role switcher TTL

**File:** `src/Models/Teams/HasTeamPermissions.php:193,214`
**Problem:** `allTeamIdsWithRoles` and `countTeamIdsWithRolesPairs` have 180s TTL. These involve hierarchy CTEs and re-execute frequently.
**Fix:** Increase to 900s (match permissions TTL). The data is already invalidated by team_role_changed events.

### 7.4: Cache Permission::findByKey results with tags

Already covered in Fix 3.6 and Section 4. This also improves performance because the short 30s TTL means frequent Redis calls. With 60s TTL + tags, we get proper invalidation without the ultra-short expiry.

### 7.5: Precompute role permissions in Redis individually

**Problem:** `PermissionResolver::preloadPermissionData()` batch-loads role and team-role permissions into `$requestCache[]` only. On the next request, even with warm Redis, the outer `getUserPermissionsOptimized` key might miss if teamIds differ.

**Fix:** Cache per-role and per-team-role permission sets individually in Redis:

```php
// In getRolePermissions(), the Cache::rememberWithTags already exists (line 327)
// but it's only reached when requestCache misses. The preloadPermissionData() writes
// to requestCache but skips the Redis write. Fix: after preloading, also warm Redis.

private function preloadPermissionData(Collection $teamRoles): void
{
    // ... existing batch DB queries ...

    // After grouping results, also write to Redis for cross-request benefit:
    $ttl = config('kompo-auth.cache.ttl', 900);
    foreach ($rolePermissions as $roleId => $permissions) {
        $key = CacheKeyBuilder::rolePermissions($roleId);
        $tags = CacheKeyBuilder::getTagsForCacheType(CacheKeyBuilder::ROLE_PERMISSIONS);
        $perms = collect($permissions)->pluck('complex_permission_key')->all();
        $this->requestCache["role_permissions.{$roleId}"] = $perms;
        Cache::tags($tags)->put($key, $perms, $ttl);
    }

    foreach ($teamRolePermissions as $teamRoleId => $permissions) {
        $key = CacheKeyBuilder::teamRolePermissions($teamRoleId);
        $tags = CacheKeyBuilder::getTagsForCacheType(CacheKeyBuilder::TEAM_ROLE_PERMISSIONS);
        $perms = collect($permissions)->pluck('complex_permission_key')->all();
        $this->requestCache["team_role_permissions.{$teamRoleId}"] = $perms;
        Cache::tags($tags)->put($key, $perms, $ttl);
    }
}
```

---

## 8. Cache Warming Strategy (Priority 6)

### Current State

- Hourly: `permissions:optimize-cache --warm` (top 100 users by role count)
- Daily 02:00: `teams:warm-hierarchy-cache` (all teams)
- Login: `refreshRolesAndPermissionsCache()` in `switchToTeamRole()`

### Proposed Tiers

```
TIER 1: On Login (sync, before response)
  Target: The logging-in user
  Warm:
    - currentTeamRole + currentTeam + isSuperAdmin
    - getUserPermissionsOptimized(userId, currentTeamId)
    - getActiveTeamRolesOptimized(userId)
  Cost: 3-5 queries (most already happen via switchToTeamRole)
  Location: Login event listener (new)

TIER 2: On Login (async, afterResponse)
  Target: The logging-in user
  Warm:
    - getAllAccessibleTeamIds()
    - getAllTeamIdsWithRolesCached()
    - Per-role permission sets (preloadPermissionData results into Redis)
  Cost: ~8-12 queries, non-blocking
  Location: Existing dispatch()->afterResponse() in refreshRolesAndPermissionsCache

TIER 3: Scheduled (hourly)
  Target: Top 100 users by RECENT ACTIVITY (not just role count)
  Warm:
    - Everything from Tier 1 + 2
    - Role permission sets (shared, benefits all users with that role)
  Selection query:
    Users with last_login_at in past 7 days, ordered by
    last_login_at DESC, team_role_count DESC
  Location: OptimizePermissionCacheCommand (modify getCriticalUsers)

TIER 4: Scheduled (daily at 02:00)
  Target: All teams
  Warm:
    - descendants, ancestors, siblings for each team
  Already exists: teams:warm-hierarchy-cache
  No changes needed.
```

### Implementation: Login Event Listener

```php
// New: src/Listeners/WarmUserCacheOnLogin.php
class WarmUserCacheOnLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        // Tier 1: sync (fast, needed immediately)
        // Already happens via switchToTeamRole/refreshRolesAndPermissionsCache
        // No extra work needed here.
    }
}
```

Actually, the existing `refreshRolesAndPermissionsCache()` already handles Tier 1+2. The main improvement is:

### Implementation: Improve getCriticalUsers

```php
// src/Teams/PermissionCacheManager.php - replace getCriticalUsers()
private function getCriticalUsers(): array
{
    return Cache::remember('critical_users_list', 3600, function () {
        return \Kompo\Auth\Facades\UserModel::query()
            ->join('team_roles', 'users.id', '=', 'team_roles.user_id')
            ->where('users.last_login_at', '>', now()->subDays(7))
            ->select('users.id')
            ->groupBy('users.id')
            ->orderByRaw('MAX(users.last_login_at) DESC')
            ->orderByRaw('COUNT(team_roles.id) DESC')
            ->limit(100)
            ->pluck('id')
            ->all();
    });
}
```

### Implementation: Warm role permission sets (shared benefit)

Add to `OptimizePermissionCacheCommand::warmCache()`:

```php
// After warming user caches, also warm shared role permission sets
$roles = Role::all();
$resolver = app(PermissionResolver::class);
foreach ($roles as $role) {
    $resolver->getRolePermissions($role); // populates Redis via Cache::rememberWithTags
}
```

---

## 9. Layered Architecture (Priority 7)

### Goal

Separate cache from business logic so services are simple and testable, and cache is a transparent layer.

### Current Problem

```
PermissionResolver has:
  - Permission resolution logic (BUSINESS)
  - requestCache[] array (CACHE)
  - Cache::rememberWithTags() calls (CACHE)
  - CacheKeyBuilder references (CACHE)
  All mixed together in every method.
```

### Proposed Architecture

```
LAYER 1: Entry Points (helpers, macros, middleware, scopes)
    |
    | calls
    v
LAYER 2: Cached Decorators (transparent cache wrappers)
    |  - CachedPermissionResolver
    |  - CachedTeamHierarchyService
    |  Both use AuthCacheLayer for all cache ops
    |
    | delegates on miss
    v
LAYER 3: Pure Services (zero cache knowledge)
    |  - PermissionResolver (just DB queries + logic)
    |  - TeamHierarchyService (just CTEs + logic)
    |
    v
LAYER 4: Database
```

### Step-by-step Migration

**This is a large refactor. Do it incrementally over multiple PRs:**

**Phase A: Extract interface + keep existing implementation**

1. Create `src/Teams/Contracts/PermissionResolverInterface.php`
2. Make current `PermissionResolver` implement it
3. Bind interface in service provider
4. Change all `app(PermissionResolver::class)` to `app(PermissionResolverInterface::class)`
5. No behavior change. Just interface extraction.

**Phase B: Split cache from PermissionResolver**

1. Create `src/Teams/Cache/AuthCacheLayer.php` (single request + Redis cache)
2. Create `src/Teams/Cache/CachedPermissionResolver.php` (decorator)
3. Move all `Cache::rememberWithTags` and `$requestCache` from `PermissionResolver` into the decorator
4. Strip `PermissionResolver` to pure logic (just query + return)
5. Bind `CachedPermissionResolver` as the implementation of `PermissionResolverInterface`
6. Current behavior preserved. Code is cleaner.

**Phase C: Same for TeamHierarchyService**

1. Create `src/Teams/Contracts/TeamHierarchyInterface.php`
2. Create `src/Teams/Cache/CachedTeamHierarchyService.php`
3. Strip cache from `TeamHierarchyService`
4. Bind cached version in provider

**Phase D: Centralize invalidation**

1. Create `src/Teams/Cache/PermissionCacheInvalidator.php`
2. Move ALL invalidation rules from model booted() hooks into this class
3. Register model event listeners in `KompoAuthServiceProvider` pointing to the invalidator
4. Remove `clearCache()` methods from individual models
5. Remove `PermissionCacheManager::invalidateByChange()` switch/case (replaced by dedicated methods)

**Phase E: Centralize warming**

1. Create `src/Teams/Cache/PermissionCacheWarmer.php`
2. Move warming logic from `PermissionCacheManager` and commands
3. Add tiered warming API

### AuthCacheLayer Design

```php
class AuthCacheLayer
{
    private array $requestCache = [];

    public function remember(string $key, string $tag, callable $compute): mixed
    {
        // 1. Request cache (fastest)
        if (array_key_exists($key, $this->requestCache)) {
            return $this->requestCache[$key];
        }

        // 2. Redis with tags
        $ttl = $this->getTtlForTag($tag);
        $tags = ['permissions-v2', $tag];

        $value = Cache::rememberWithTags($tags, $key, $ttl, $compute);
        $this->requestCache[$key] = $value;

        return $value;
    }

    public function invalidateTag(string $tag): void
    {
        Cache::flushTags([$tag]);
        $this->flushRequestCacheForTag($tag);
    }

    public function invalidateAll(): void
    {
        Cache::flushTags(['permissions-v2']);
        $this->requestCache = [];
    }

    public function flushRequestCache(): void
    {
        $this->requestCache = [];
    }

    private function getTtlForTag(string $tag): int
    {
        return match($tag) {
            'team_descendants', 'team_ancestors', 'team_siblings',
            'team_is_descendant' => config('kompo-auth.cache.hierarchy_ttl', 3600),
            'user_super_admin', 'is_super_admin' => config('kompo-auth.cache.super_admin_ttl', 3600),
            'role_definitions' => config('kompo-auth.cache.role_list_ttl', 3600),
            'permission_definitions' => config('kompo-auth.cache.permission_lookup_ttl', 60),
            default => config('kompo-auth.cache.ttl', 900),
        };
    }
}
```

### CachedPermissionResolver Design

```php
class CachedPermissionResolver implements PermissionResolverInterface
{
    public function __construct(
        private PermissionResolver $inner,
        private AuthCacheLayer $cache,
    ) {}

    public function userHasPermission(int $userId, string $permissionKey, PermissionTypeEnum $type, $teamIds = null): bool
    {
        $key = CacheKeyBuilder::userPermissions($userId, $teamIds);

        $permissions = $this->cache->remember($key, CacheKeyBuilder::USER_PERMISSIONS, function () use ($userId, $teamIds) {
            return $this->inner->getUserPermissions($userId, $teamIds);
        });

        // Check logic is cheap, runs in-memory on cached data
        if ($this->inner->hasExplicitDeny(collect($permissions), $permissionKey)) {
            return false;
        }

        return $this->inner->hasRequiredPermission(collect($permissions), $permissionKey, $type);
    }

    // ... other methods follow same pattern
}
```

---

## 10. File-by-File Change Map

### Priority 1: Bug Fixes (do first, minimal risk)

```
src/Models/Teams/PermissionTeamRole.php
  Line 25-28: Fix clearCache() to resolve user_id from team_role

src/Models/Teams/Roles/Role.php
  Line 160-169: Add cache invalidation to createOrUpdatePermission()
  Line 44-51: Replace Cache::forget('roles') with flushTags(['role_definitions'])

src/Teams/Roles/RoleRequestsUtils.php
  Line 37: Replace flushTags(['permissions']) with PermissionCacheManager call
  Line 60: Same

src/Teams/Roles/RoleForm.php
  Line 39-45: Replace Cache::flush() with targeted PermissionCacheManager call

src/Models/Teams/Team.php
  Line 253-267: Add $user->clearPermissionCache() after detach in detachFromTeam()

src/Models/Teams/Permission.php
  Add booted() hook with saved/deleted events that invalidate cache

src/Teams/Roles/EditPermissionInfo.php
  Line 25-28: Add PermissionCacheManager invalidation after existing forget()

src/Helpers/roles.php
  Line 48-63: Use Cache::rememberWithTags with 'role_definitions' tag
```

### Priority 2: Tag Unification

```
src/Models/Teams/TeamRole.php
  Line 141: Change tag from ['permissions'] to ['permissions-v2', 'team_role_permissions']

src/Models/Teams/PermissionSection.php
  Line 25: Change tag from ['permissions'] to ['permissions-v2', 'permission_definitions']

src/Models/Teams/Permission.php
  Line 47: Add tags ['permissions-v2', 'permission_definitions'] to findByKey()
```

### Priority 3: TTL Centralization

```
config/kompo-auth.php
  Line 50-56: Add sub-keys for each TTL tier

src/Teams/PermissionResolver.php
  Line 21: Replace const with config() read

src/Teams/TeamHierarchyService.php
  Line 16: Replace const with config() read

src/Helpers/auth.php
  Lines 171, 195, 234: Replace hardcoded TTLs with config() reads

src/Models/Teams/HasTeamPermissions.php
  Lines 92, 147, 167, 193, 214, 498, 516: Replace hardcoded TTLs
```

### Priority 4: Request Lifecycle

```
src/KompoAuthServiceProvider.php
  Add app()->terminating() callback in boot() for all static caches

src/Models/Plugins/Services/Traits/SecurityConfigTrait.php
  Add static clearCache() method

src/Support/ElementPermissionCache.php (NEW)
  Resettable static cache for element macros

src/Helpers/auth.php
  Refactor macro closures to use ElementPermissionCache
```

### Priority 5: Performance

```
src/Teams/TeamHierarchyService.php
  Add getBatchAncestorTeamIds() method
  Add getBatchSiblingTeamIds() method (not the withRoles variant, just IDs)

src/Teams/PermissionResolver.php
  Line 199-210: Use batch ancestor/sibling methods

src/Models/Teams/Roles/Role.php
  Lines 113, 153: Replace count() > 0 with exists()

src/Models/Teams/HasTeamPermissions.php
  Lines 193, 214: Increase TTL from 180 to 900

src/Teams/PermissionResolver.php
  preloadPermissionData(): Write individual role/team-role results to Redis
```

### Priority 6: Cache Warming

```
src/Teams/PermissionCacheManager.php
  getCriticalUsers(): Use last_login_at + role count composite

src/Commands/OptimizePermissionCacheCommand.php
  warmCache(): Also warm shared role permission sets
```

### Priority 7: Layered Architecture (multiple PRs)

```
Phase A:
  src/Teams/Contracts/PermissionResolverInterface.php (NEW)
  src/Teams/PermissionResolver.php (implements interface)
  src/KompoAuthServiceProvider.php (bind interface)

Phase B:
  src/Teams/Cache/AuthCacheLayer.php (NEW)
  src/Teams/Cache/CachedPermissionResolver.php (NEW)
  src/Teams/PermissionResolver.php (strip cache, pure logic)
  src/KompoAuthServiceProvider.php (bind cached decorator)

Phase C:
  src/Teams/Contracts/TeamHierarchyInterface.php (NEW)
  src/Teams/Cache/CachedTeamHierarchyService.php (NEW)
  src/Teams/TeamHierarchyService.php (strip cache, pure logic)

Phase D:
  src/Teams/Cache/PermissionCacheInvalidator.php (NEW)
  src/KompoAuthServiceProvider.php (register event listeners)
  Remove clearCache() from Team, TeamRole, Role, PermissionRole, PermissionTeamRole

Phase E:
  src/Teams/Cache/PermissionCacheWarmer.php (NEW)
  Move warming from PermissionCacheManager and commands

Cleanup:
  src/Teams/UnifiedCacheService.php (DELETE - never used)
  src/Teams/PermissionCacheManager.php (reduced to thin wrapper or deleted)
```

---

## Diagrams

### Cache Flow (Proposed)

```
  Request arrives
       │
       ▼
  ┌─────────────┐    hit    ┌──────────────────┐
  │ Request     │──────────▶│ Return cached     │
  │ Cache       │           │ value             │
  │ (in-memory) │           └──────────────────┘
  └──────┬──────┘
         │ miss
         ▼
  ┌─────────────┐    hit    ┌──────────────────┐
  │ Redis       │──────────▶│ Store in request  │
  │ (tagged)    │           │ cache + return    │
  └──────┬──────┘           └──────────────────┘
         │ miss
         ▼
  ┌─────────────┐           ┌──────────────────┐
  │ Pure Service│──────────▶│ Store in Redis    │
  │ (DB query)  │           │ + request cache   │
  └─────────────┘           │ + return          │
                            └──────────────────┘
```

### Invalidation Flow (Proposed)

```
  Model event fires (saved/deleted)
       │
       ▼
  ┌──────────────────────────────────┐
  │ PermissionCacheInvalidator       │
  │ (centralized, single file)       │
  │                                  │
  │ Routes by event type:            │
  │  TeamRole.saved    → flush user  │
  │  PermTeamRole.saved → flush user │
  │  PermRole.saved    → flush role  │
  │  Role.saved        → flush role  │
  │  Team.saved        → flush team  │
  │  Permission.saved  → flush all   │
  └──────────┬───────────────────────┘
             │
             ▼
  ┌──────────────────────────────────┐
  │ AuthCacheLayer                   │
  │                                  │
  │ invalidateTag('user_permissions')│
  │  → Cache::tags([tag])->flush()   │
  │  → clear matching requestCache   │
  └──────────────────────────────────┘
```

### State Change → Cache Invalidation (Proposed, all paths covered)

```
MUTATION                          INVALIDATOR METHOD            TAGS FLUSHED
──────────────────────────────────────────────────────────────────────────────
Team saved (parent changed)       onTeamHierarchyChanged()      team_descendants,
                                                                team_ancestors,
                                                                team_siblings,
                                                                team_is_descendant,
                                                                accessible_teams

Team saved (created)              onTeamCreated()               team_descendants,
                                                                team_ancestors,
                                                                team_siblings,
                                                                user_all_accessible_teams

Team deleted                      onTeamDeleted()               Same as hierarchy +
                                                                current_team_role,
                                                                current_team

Team.detachFromTeam(user)         onUserRemovedFromTeam()       user_permissions,
                                                                user_teams_with_permission,
                                                                user_all_accessible_teams,
                                                                current_team_role,
                                                                current_team

TeamRole saved/deleted            onTeamRoleChanged()           user_permissions,
                                                                user_teams_with_permission,
                                                                user_all_accessible_teams,
                                                                team_role_access,
                                                                team_role_permissions,
                                                                current_team_role,
                                                                current_team

PermissionTeamRole saved/deleted  onPermissionTeamRoleChanged() user_permissions,
                                                                team_role_permissions

PermissionRole saved/deleted      onPermissionRoleChanged()     role_permissions,
                                                                user_permissions,
                                                                user_teams_with_permission

Role saved/deleted                onRoleChanged()               role_permissions,
                                                                role_definitions,
                                                                user_permissions

Role.createOrUpdatePermission()   (fires PermissionRole events  role_permissions,
                                   OR explicit call)            user_permissions

$role->permissions()->detach()    (model events handled by      role_permissions
                                   PermissionRole.deleted)

Permission saved/deleted          onPermissionChanged()         permission_definitions,
                                                                permissions-v2 (full flush)

User.switchToTeamRole             onUserContextChanged()        current_team_role,
                                                                current_team,
                                                                is_super_admin
```
