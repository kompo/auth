# Owned Records

Single source of truth for "which records of `ModelClass` does user `U` own".

## The contract

```php
use Kompo\Auth\Contracts\Security\HasOwnedRecords;

class Note extends ModelBase implements HasOwnedRecords
{
    public function ownedRecordIdsForUser(int $userId): array
    {
        // Implementations MAY query freely — the resolver wraps the call in a
        // bypass context, so security scopes won't recurse.
        return $this->newQuery()->where('author_id', $userId)->pluck('id')->all();
    }
}
```

## The resolver

```php
use Kompo\Auth\Teams\Security\Contracts\OwnedRecordsResolverInterface;

$ids   = app(OwnedRecordsResolverInterface::class)->forUser($userId, Note::class);
$owned = app(OwnedRecordsResolverInterface::class)->isOwnedBy($userId, Note::class, $note->id);
```

The container-bound implementation is `CachedOwnedRecordsResolver` wrapping the pure `OwnedRecordsResolver`. The pure resolver is also registered as a singleton if you need it in tests.

## Resolution precedence

The resolver picks one of three strategies, first-match:

1. **`HasOwnedRecords` contract** — preferred for any new model.
2. **`scopeUserOwnedRecords()` query scope** — legacy. Only used when `$userId === auth()->id()` because the scope reads current auth state.
3. **`user_id` column auto-detect** — falls through when neither of the above is present and the table has a `user_id` column.

Models with none of these return `[]`.

## Caching

- **Where:** `CachedOwnedRecordsResolver::$cache` keyed by `[modelClass][userId]`.
- **Scope:** per request.
- **Flushed:**
  - At request termination, with the rest of the security caches (`KompoAuthServiceProvider::registerRequestLifecycleCleanup`).
  - On save/delete of a model whose class has owned-record semantics (`HasSecurity::cleanupModelTracking` → `CachedOwnedRecordsResolver::flushFor($class)`). Coarse — flushes the whole class entry, not per user, since writes are rare relative to reads.

## Who uses the resolver

- `ReadSecurityService` — both the team-path `OR IN(owned_ids)` clause and the non-team-path `WHERE IN(owned_ids)` restriction.
- `BatchPermissionService::bulkResolveOwnedModels` — flags matching rows as bypassed during batch field protection.
- `SecurityBypassService::hasBypassByScope` — O(1) check against the cached id set.

These were previously four independent paths each computing the same thing differently. The resolver collapses them.

## Migrating from the legacy APIs

| Legacy | Replacement |
|---|---|
| `protected function scopeUserOwnedRecords($q)` | `implements HasOwnedRecords` + `ownedRecordIdsForUser(int $userId): array` |
| `public function usersIdsAllowedToManage(): array` | Same — model implements `HasOwnedRecords` returning the appropriate ids for `$userId` |
| Calling `Model::query()->userOwnedRecords()->pluck($pk)` directly | `app(OwnedRecordsResolverInterface::class)->forUser(auth()->id(), Model::class)` |

`scopeUserOwnedRecords` keeps working as a fallback (Strategy 2 above) — no rush to remove it from existing models.

## Cautions

- The resolver runs the strategy inside `SecurityBypassService::enterBypassContext()`. Implementations of `ownedRecordIdsForUser` should not also enter/exit bypass themselves — the wrap is already in place.
- Cache invalidation is per-class. If you bulk-update ownership outside the model's save/delete events, call `CachedOwnedRecordsResolver::flushFor(YourClass::class)` yourself.
