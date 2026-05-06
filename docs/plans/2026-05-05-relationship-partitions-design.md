# Relationship Partitions — Design

**Date:** 2026-05-05
**Status:** Design (not implemented)
**Owner:** Bruno
**Context:** kompo-auth — extending `HasSecurity` to support row-level partition filtering on relations, driven from the parent model.

## 1. Problem

The current `sensibleRelationships` mechanism is binary: a related collection is either fully accessible or fully blocked (`whereRaw('1=0')`). There is no first-class way to say "load `Person->notes`, but exclude restricted rows unless the user has the elevated permission".

Today, the only workaround is to push the filter into the related model's root scope (e.g. SISC's `HasAudienceVisibilityTrait`, which overrides `scopeSecurityForTeams` on `Event`). That works when the related model is *always* viewed under the same partition rule, but it doesn't generalize:

- It couples the related model (`Note`) to every parent's permission semantics.
- It forces you to discard the standard team-based root interception when you want fine row filtering.
- It makes standalone access to the related model behave the same as parent-driven access, which is often wrong.

## 2. Goal

Add a parent-driven, row-level partition mechanism on top of the existing relationship-protection pipeline:

- The **parent** declares which relations have which partitions and owns the permission keys.
- The **related model** declares one query scope per partition describing how to *exclude* rows of that partition.
- Auth applies the exclusion scopes on the relation's query when the user lacks the matching permission.
- The related model's existing root scope (team/owner filtering via `HasSecurity`) is left untouched.

## 3. Public API

### 3.1 Parent declaration

```php
class Person extends Model
{
    use HasModelPlugins; // already wires HasSecurity

    protected $sensibleRelationshipPartitions = [
        'notes'       => ['restricted', 'internal'],
        'attachments' => ['confidential'],
    ];
}
```

- Keys are relation method names on the parent.
- Values are lists of partition names.
- Each partition is gated by permission key `{getPermissionKey($parent)}.{relation}.{partition}` (e.g. `Person.notes.restricted`).
- The list ordering is irrelevant; exclusion scopes compose via AND.

### 3.2 Related-model declaration

```php
class Note extends Model
{
    public function scopeExcludeRestricted($q) { $q->where('notes.visibility', '!=', 'restricted'); }
    public function scopeExcludeInternal($q)   { $q->where('notes.visibility', '!=', 'internal'); }
}
```

- One scope per partition, fixed convention `scopeExclude{StudlyPartition}`.
- Scopes describe how to *remove* the partition's rows; auth invokes them when the user lacks the matching permission.
- Always qualify column names with the table (`notes.visibility`) so the scope is safe inside eager-load joins.
- Use top-level `where(...)` only. If multi-clause logic is needed, wrap in a closure: `$q->where(function ($q) { $q->where(...)->orWhere(...); })`. Do **not** start a scope with `orWhere(...)` — it would break composition.

### 3.3 Permission key overrides (3-step, mirrors existing pattern)

1. **Method override** on the parent — `getRelationshipPartitionPermissionKey(string $relation, string $partition): string`. Tightest control.
2. **Property override** on the parent — `protected $relationshipPartitionPermissionKeys = ['notes.restricted' => 'PersonalNotes.read'];`. Static map.
3. **Convention** — `{parentKey}.{relation}.{partition}`.

### 3.4 Behavioural summary

When the user accesses `$person->notes`, `$person->notes()->...`, or eager-loads via `Person::with('notes')`:

- For each partition declared on `notes`, auth checks the corresponding permission.
- Each missing permission appends one `excludeX()` to the relation query (composed via AND).
- All permissions present → no scope appended; full visibility.
- Direct `Note::query()` with no parent context → unchanged. Partition logic does not fire.

## 4. Permission-key resolution

Resolved once per parent class in `SecurityMetadataRegistry::compute()` and cached for the request lifetime. Resolution is parental, not target-domain: two parents can classify the same target rows differently (Person → "restricted", Team → "internal"), and each parent's overrides are independent.

DB-existence rule is unchanged: if the resolved permission key has no row in the `permissions` table, `permissionMustBeAuthorized()` returns false → no check, no exclusion. New partitions can ship as code-first; enforcement begins when the permission row is added.

## 5. Interception path

Single hook point: `HasSecurity::interceptRelation($model, $relation, $relationName)`. A new branch runs *before* the existing full-block check.

```
interceptRelation:
  if (in bypass context)             return false;
  $meta = SecurityMetadataRegistry::for(get_class($model));
  if (!$meta['hasProtection'])       return false;

  // Existing full-block path
  if ($meta['protectedRelationships'][$relationName] && state->isRelationBlocked) {
      return $relation->whereRaw('1=0');
  }

  // NEW: partition path
  if (isset($meta['partitions'][$relationName])) {
      foreach ($meta['partitions'][$relationName] as $partition) {
          if (!permissionMustBeAuthorized($partition['permKey'])) continue;
          if ($fieldProtectionService->hasPermissionForProtectionKey($model, $partition['permKey'])) continue;
          $relation = $relation->{$partition['scope']}();   // e.g. excludeRestricted()
      }
      return $relation;
  }

  // Existing lazy-block resolution
  return resolveRelationBlockingLazy(...);
```

Why one hook covers all paths:

- `$person->notes` (property) → `getRelationshipFromMethod` calls the method → `interceptRelation` runs.
- `$person->notes()->where(...)` (method) → `interceptRelation` runs at relation creation, scopes apply before user predicates.
- `Person::with('notes')` (eager) → Laravel calls the relation method → `interceptRelation` runs once for the whole eager batch.

All three paths filter at SQL — no post-load stripping needed.

### 5.1 Metadata extension

`SecurityMetadataRegistry::compute()` collects a new key:

```php
'partitions' => [
    'notes' => [
        ['name' => 'restricted', 'permKey' => 'Person.notes.restricted', 'scope' => 'excludeRestricted'],
        ['name' => 'internal',   'permKey' => 'Person.notes.internal',   'scope' => 'excludeInternal'],
    ],
],
```

Computed once per parent class. `interceptRelation` reads via `isset($meta['partitions'][$relationName])` → O(1) skip when not partitioned. Resolution applies the override hooks from §3.3.

### 5.2 Strict failure mode (fail closed)

If a partition is declared on the parent but the corresponding `scopeExclude{Partition}` does **not** exist on the related model, metadata computation throws `RuntimeException("Note::scopeExcludeRestricted is required by Person::sensibleRelationshipPartitions['notes']")`. Loud at boot, prevents silent leaks. Unlike DB-discovery for columns (where missing column = obviously skip), a missing scope means the developer wired a partition but forgot to define how it excludes — fail closed.

## 6. Composition rules and edge cases

### 6.1 Multi-partition composition

Each missing permission appends one exclusion scope. Order doesn't matter; all scopes are AND-composed at SQL. A user lacking all permissions on a relation with three partitions sees the intersection of all three exclusions.

### 6.2 Conflict with full-block sensibleRelationships

If a relation appears in both `$sensibleRelationships` (full block) and `$sensibleRelationshipPartitions`, the full-block path wins (it's strictly more restrictive). Metadata computation logs a warning naming both declarations — this is almost always a configuration mistake.

### 6.3 Bypass interaction

- `SecurityBypassService::isInBypassContext()` (set by `asSystemOperation`, etc.) — partitions are skipped, identical to full-block today.
- Per-query bypass scopes (`alreadyVerifiedAccess()`, `withinCurrentTeamContext()`) operate on the related model's *root* scope. They are applied **after** relation creation, so partitions added by `interceptRelation` remain in effect. To bypass partitions for a specific operation, query the related model directly instead of via the parent: `Note::alreadyVerifiedAccess()->where(...)` — same documented escape hatch as today.
- Owner bypass on the parent does **not** lift partitions. Partitions are about contextual visibility within the parent's domain, not about record ownership of children.

### 6.4 Existence checks

`Person::whereHas('notes', ...)` builds a subquery directly via Laravel; it does not call the relation method, so `interceptRelation` does not fire. Partitions therefore do **not** apply through `whereHas`. This is identical to the existing full-block limitation. Developers needing partition-aware existence checks compose manually:

```php
Person::whereHas('notes', fn ($q) => $q->excludeRestricted())->get();
```

Document the limitation in the same place the full-block caveat is documented.

### 6.5 Polymorphic relations

- `morphMany`/`morphOne` to a single concrete type — works as expected; the scope is on the target model.
- `morphTo` (polymorphic parent) — partitions are not supported, because the target type varies per row and a single `scopeExclude{Partition}` cannot be guaranteed across all possible targets. Metadata computation rejects partitions declared on a `morphTo` relation with a clear error.

### 6.6 Pivot / `belongsToMany`

The exclusion scope is applied to the related-model query, so it operates on the related table. Pivot columns are not in scope. If a partition needs to filter by a pivot column, the developer writes the scope to join the pivot explicitly — same constraint as any other Eloquent scope on a `belongsToMany` target.

### 6.7 `kompo-utils.intercept-relations` config

When this config is `false`, `interceptRelation` is bypassed entirely. Partitions on method calls (`$person->notes()->get()`) will not fire. Property access (`$person->notes`) still works because `getRelationshipFromMethod` is wired separately and we add the same partition logic there. Same caveat as full-block today; documented in the same place.

## 7. Performance

- **Metadata** — computed once per parent class, cached for the request. No reflection on hot path.
- **Per relation invocation** — O(P) where P is the number of partitions on that relation. Each iteration is one cached permission lookup (`hasPermissionForProtectionKey` already caches per-request) + one method call on the relation builder.
- **No additional DB queries** beyond the permission cache the existing system already populates.
- **Eager loading** — `interceptRelation` runs once per eager batch, not once per parent. The exclusion scopes apply to the single eager query.
- **Skip cost when no partitions** — single `isset($meta['partitions'][$relationName])` lookup; same shape as the existing full-block fast path.

## 8. Migration & rollout

1. Land code (parent property, scope methods, plugin extension). Backwards compatible — partitions are purely additive; models without `$sensibleRelationshipPartitions` are unaffected.
2. Add `permissions` rows for the new keys when you're ready to enforce. Until rows exist, `permissionMustBeAuthorized` returns false → no exclusion → behaviour identical to today.
3. Grant the new permission keys to the appropriate roles.
4. (Optional) Move existing relations from `$sensibleRelationships` (full-block) to `$sensibleRelationshipPartitions` if you discover the binary block was over-restrictive.

## 9. Out of scope (explicit YAGNI)

- **Column-level partitions on the parent** — already covered by `sensibleColumns` / `sensibleColumnsGroups`. No new mechanism needed.
- **DB-discovery of partitions** — possible (scan `Permission` rows matching `{parentKey}.{relation}.*` and auto-register where the scope method exists), but adds complexity and a silent-coupling risk. Defer until a concrete need lands.
- **Positive scopes (`scopeRestricted`)** — auth doesn't need them. If a developer wants the positive direction for their own queries, they declare it themselves.
- **Cross-target partition naming registry** — partitions are scoped to `{parent, relation}` and don't need global names.

## 10. File-level change list

- `src/Models/Plugins/Services/SecurityMetadataRegistry.php` — extend `compute()` to collect `partitions`. Add `resolvePartitionPermissionKey()` and validation that scope methods exist (throw on missing).
- `src/Models/Plugins/HasSecurity.php` — add partition branch to `interceptRelation()` and to `getRelationshipFromMethod()`. No changes to attribute-access path (partitions filter at query time only).
- Helpers (`src/Helpers/auth.php`) — no changes; existing `permissionMustBeAuthorized` is reused.
- Docs — extend the Permission System guide with a "Relationship partitions" subsection mirroring the §6 caveats.
- Tests — at least: single-partition exclude-on-no-perm; multi-partition compose; eager-load applies; `whereHas` does not (documented); morphTo rejected; missing scope throws; full-block conflict warns; bypass context skips.
