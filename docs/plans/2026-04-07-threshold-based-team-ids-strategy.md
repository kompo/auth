# Threshold-Based Team IDs Strategy

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Prevent OOM from massive `IN (?, ?, ...)` clauses by automatically switching from cached ID arrays to subqueries when the team count exceeds a configurable threshold.

**Architecture:** `ReadSecurityService` currently always uses `getTeamsIdsWithPermission()` (Strategy 2/3) which returns a cached array of team IDs. With ~1,500 teams repeated 5+ times in a query, this creates 7,895+ bindings that exhaust PHP memory. The fix adds a threshold check: below the threshold, use the current cached ID array (fast, indexed); above it, pass a query builder to `whereIn()` instead (small query, no binding explosion). Laravel's `whereIn()` natively accepts both arrays and query builders, and all existing `scopeSecurityForTeams` implementations use `whereIn()` exclusively, making this change transparent.

**Tech Stack:** Laravel Eloquent, PDO, kompo-auth security plugin system

---

## Research Summary

### Current Architecture

Two independent methods exist on the User model (via `HasTeamPermissions`):

| Method | Returns | Cached | Used By |
|--------|---------|--------|---------|
| `getTeamsIdsWithPermission()` | `Collection` of IDs | Yes (900s TTL) | Strategy 2 (`scopeSecurityForTeams`) and Strategy 3 (column `whereIn`) |
| `getTeamsQueryWithPermission()` | `Query\Builder` | No | Strategy 1 (`scopeSecurityForTeamByQuery`) - **no model implements this** |

### Key Facts

- **`getTeamsIdsWithPermission`** calls `PermissionResolver::getTeamsWithPermissionForUser()` which computes team IDs in PHP and caches them for 900 seconds.
- **`getTeamsQueryWithPermission`** calls `PermissionResolver::getTeamsQueryWithPermissionForUser()` which builds an SQL subquery (never cached, never executed standalone).
- **All 8 `scopeSecurityForTeams` implementations** across auth, crm, and SISC use `whereIn()` which natively accepts query builders. No implementation calls `->toArray()`, `->count()`, or iterates the parameter.
- **`scopeSecurityForTeamByQuery` (Strategy 1)** is supported in `ReadSecurityService` but **zero models implement it**.
- **No existing config** controls the choice between IDs vs subqueries.
- **`getUserTeamsQuery()`** in `ReadSecurityService` passes `$this->getModelTable()` as alias (designed for Strategy 1 correlated subqueries). For Strategy 2 `whereIn()`, we need the default `'teams'` alias so the subquery returns `teams.id`.

### Why Threshold-Based

| Team Count | ID Array Behavior | Subquery Behavior |
|------------|-------------------|-------------------|
| < 100 | Fast, cached, few bindings | Unnecessary overhead |
| 100-500 | Works, moderate bindings | Either works well |
| 1,500+ | 7,895 bindings, OOM risk | Small subquery, no binding explosion |

---

## Implementation Tasks

### Task 1: Add config option

**Files:**
- Modify: `config/kompo-auth.php` - add threshold to security section

**Step 1: Add config key**

In `config/kompo-auth.php`, inside the `'security'` array, add:

```php
// Maximum number of team IDs to pass as literal IN (?,...) bindings.
// Above this threshold, a subquery is used instead to avoid PDO memory exhaustion.
// Set to 0 to always use subquery, or null to always use ID array.
'team-ids-query-threshold' => 200,
```

**Step 2: Verify config loads**

Run: `php artisan tinker --execute="echo config('kompo-auth.security.team-ids-query-threshold');"`
Expected: `200`

---

### Task 2: Add `getUserTeamIdsSubquery()` method to ReadSecurityService

**Files:**
- Modify: `src/Models/Plugins/Services/ReadSecurityService.php`

**Why this is needed:** The existing `getUserTeamsQuery()` passes `$this->getModelTable()` as the table alias, making the subquery return `{model_table}.id` instead of `teams.id`. For `whereIn('team_id', $subquery)`, we need `teams.id`.

**Step 1: Add the method**

After the existing `getUserTeamsQuery()` method (~line 287), add:

```php
/**
 * Get a subquery returning team IDs (using default 'teams' alias).
 * Unlike getUserTeamsQuery(), this is safe for use in whereIn() clauses
 * because it selects teams.id, not {model_table}.id.
 */
protected function getUserTeamIdsSubquery()
{
    return auth()->user()?->getTeamsQueryWithPermission(
        $this->permissionKey,
        PermissionTypeEnum::READ
    );
}
```

---

### Task 3: Add `getTeamIdsOrSubquery()` resolver method

**Files:**
- Modify: `src/Models/Plugins/Services/ReadSecurityService.php`

**Step 1: Add the threshold resolver**

```php
/**
 * Returns team IDs as array (cached, fast) when below threshold,
 * or as a subquery builder when above threshold (avoids binding explosion).
 * 
 * @return \Illuminate\Support\Collection|\Illuminate\Database\Query\Builder
 */
protected function getTeamIdsOrSubquery()
{
    $threshold = config('kompo-auth.security.team-ids-query-threshold');

    // null = always use ID array (legacy behavior)
    if (is_null($threshold)) {
        return $this->getUserAuthorizedTeamIds();
    }

    // 0 = always use subquery
    if ($threshold === 0) {
        return $this->getUserTeamIdsSubquery() ?? $this->getUserAuthorizedTeamIds();
    }

    // Threshold check: get cached IDs first (cheap), check count
    $teamIds = $this->getUserAuthorizedTeamIds();

    if ($teamIds->count() <= $threshold) {
        return $teamIds;
    }

    // Above threshold: prefer subquery to avoid massive IN clause
    return $this->getUserTeamIdsSubquery() ?? $teamIds;
}
```

**Design notes:**
- `getUserAuthorizedTeamIds()` is always called first because it's cached (900s TTL) - no extra DB query.
- The count check is O(1) on a Collection.
- If the subquery builder isn't available (user is null), falls back to the ID array regardless.
- Config values: `200` = threshold, `0` = always subquery, `null` = always IDs (opt-out).

---

### Task 4: Wire `getTeamIdsOrSubquery()` into Strategy 2 and 3

**Files:**
- Modify: `src/Models/Plugins/Services/ReadSecurityService.php`

**Step 1: Update Strategy 2**

Change `applyScopeBasedTeamSecurity()` from:

```php
$teamIds = $this->getUserAuthorizedTeamIds();
$query->securityForTeams($teamIds);
```

To:

```php
$query->securityForTeams($this->getTeamIdsOrSubquery());
```

**Step 2: Update Strategy 3**

Change `applyColumnBasedTeamSecurity()` from:

```php
$teamIds = $this->getUserAuthorizedTeamIds();
$query->whereIn($this->getModelTable() . '.' . $teamIdColumn, $teamIds);
```

To:

```php
$query->whereIn($this->getModelTable() . '.' . $teamIdColumn, $this->getTeamIdsOrSubquery());
```

---

### Task 5: Fix Team.php missing return statement

**Files:**
- Modify: `src/Models/Teams/Team.php:247`

**Step 1: Add return**

The research found `Team::scopeSecurityForTeams()` is missing a `return` statement. Change:

```php
public function scopeSecurityForTeams($query, $teamIds)
{
    $query->where(fn($q) => ...);
}
```

To:

```php
public function scopeSecurityForTeams($query, $teamIds)
{
    return $query->where(fn($q) => ...);
}
```

---

### Task 6: Remove debug logging from export

**Files:**
- Modify: `../utils/src/Services/Exports/ComponentToExportableToExcel.php`

Remove all `\Log::info('EXPORT_MEM ...')` calls added during investigation. These were diagnostic only.

---

### Task 7: Manual testing

**Step 1: Test with low threshold**

Set `team-ids-query-threshold` to `5` in config. Load the members list page normally. Verify:
- Page loads correctly
- No permission errors
- SQL uses subquery: check `telescope` or `DB::enableQueryLog()` for `IN (SELECT teams.id FROM ...)`

**Step 2: Test with high threshold**

Set `team-ids-query-threshold` to `10000`. Load the same page. Verify:
- Page loads correctly
- SQL uses literal IDs: `IN (1, 2, 3, ...)`

**Step 3: Test export**

Set `team-ids-query-threshold` to `200`. Trigger the volunteer export. Verify:
- Export completes without OOM
- Data is correct (same rows as visible in paginated list)

**Step 4: Test null (opt-out)**

Set `team-ids-query-threshold` to `null`. Verify original behavior is preserved (always uses ID array).

---

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Subquery slower than cached IDs for small sets | Threshold ensures small sets always use cached IDs |
| `scopeSecurityForTeams` might handle query builder differently in future implementations | All current implementations use `whereIn()` which handles both; document this requirement |
| Subquery not cached by database between repeated uses in same query | MySQL subquery cache/optimization should handle this; the alternative (OOM) is worse |
| Config default too low = unnecessary subqueries | Default 200 is conservative; 1,500 teams is where OOM starts |

## Not In Scope

- Implementing `scopeSecurityForTeamByQuery` on individual models (Strategy 1) - this is a separate optimization
- Changing the query structure of `scopeSecurityForTeams` implementations
- Adding query-level caching for the subquery builder
- Modifying the `PermissionResolver` caching strategy
