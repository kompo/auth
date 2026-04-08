# Security Plugin Performance Architecture Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Redesign the security plugin hot path to eliminate redundant processing, make caching obvious, and reduce a 25-person page from ~15,000+ security calls to under 1,000.

**Architecture:** Three-layer approach: (1) Class-level metadata cached once at boot, (2) Instance-level security state resolved once at retrieved/batch time, (3) Attribute/relation access does O(1) lookups only — no service calls, no getKey chains, no initializeServices.

**Tech Stack:** PHP 8.1+, Laravel Eloquent, kompo/auth HasSecurity plugin, kompo/utils HasModelPlugins trait

---

## Problem Statement

The current architecture has the security plugin running on every single Eloquent attribute access and relationship method call. For a page loading 25 Person models (each with ~20 attributes and ~17 relationships), this produces:

- `getAttribute` plugin: **4,300+ calls** just for `Person.id` alone
- `getAttributes` plugin: **11,000+ calls** (now bypassed but was the original bottleneck)
- `interceptRelationIfNeeded`: **2,100+ calls**
- `isBlockedRelationship`: **hundreds of calls** for the same relationships on the same model

Root causes:
1. **No separation between "what to protect" (class-level, static) vs "is this instance protected" (resolved once) vs "check on access" (should be O(1) lookup)**
2. **`getModelKey()` calls `getKey()` which triggers `getAttribute('id')` which triggers the whole plugin chain** — circular dependency protected by guards but still fires thousands of times
3. **`pluginInterceptRelation` creates fresh HasSecurity instances** with `new $plugin($this)` on every relationship method call
4. **`initializeServices()` called from every getAttribute** — even though services are cached, the method call + null check happens thousands of times
5. **Dead code** (`handleGetAttributes` early return), **debug traces everywhere**, **unclear static array lifecycle**

## Design Principles

1. **Resolve once, lookup many** — Security decisions for a model instance should be computed ONCE (at retrieved/batch time), then accessed via O(1) lookups
2. **Class metadata is static** — Protection groups, permission keys, protected relationships are per-class, not per-instance. Compute once at boot.
3. **The hot path does zero service calls** — `getAttribute`, `interceptRelation`, `getRelationshipFromMethod` should do array lookups only
4. **Model key without getKey()** — Store the model key on the instance directly, never call `getKey()` from security code
5. **Tracing is configurable** — Not inline code, controlled by config flag

---

## Task 1: Create SecurityMetadataRegistry (class-level cache)

**Goal:** Extract all class-level metadata (protection groups, permission keys, protected relationships, protected columns) into a single static registry that's populated once per class.

**Files:**
- Create: `auth/src/Models/Plugins/Services/SecurityMetadataRegistry.php`

**What it replaces:**
- `$protectedColumnsCache` in FieldProtectionService
- `$protectedRelationshipsCache` in FieldProtectionService
- `$protectionGroupsCache` in FieldProtectionService
- `getPermissionKey()` in HasSecurity (the one that creates `new Model()` instances)
- `collectProtectionGroups()` calls from hot path

**Step 1: Write SecurityMetadataRegistry**

```php
class SecurityMetadataRegistry
{
    // Computed once per model class, never changes during request
    protected static $metadata = [];

    public static function for(string $modelClass): array
    {
        if (!isset(static::$metadata[$modelClass])) {
            static::$metadata[$modelClass] = static::compute($modelClass);
        }
        return static::$metadata[$modelClass];
    }

    protected static function compute(string $modelClass): array
    {
        $model = new $modelClass;

        // Permission key (3-step resolution — done ONCE)
        $permissionKey = static::resolvePermissionKey($model, $modelClass);

        // All protection groups (sensibleColumns, sensibleColumnsGroups,
        // sensibleRelationships, sensibleRelationshipsGroups, DB-discovered)
        $groups = static::collectGroups($model, $permissionKey);

        // Pre-compute flat lists for O(1) lookup
        $protectedColumns = [];
        $protectedRelationships = [];
        foreach ($groups as $group) {
            if ($group['type'] === 'columns') {
                $protectedColumns = array_merge($protectedColumns, $group['fields']);
            } else {
                $protectedRelationships = array_merge($protectedRelationships, $group['fields']);
            }
        }

        return [
            'permissionKey' => $permissionKey,
            'groups' => $groups,
            'protectedColumns' => array_unique($protectedColumns),
            'protectedRelationships' => array_unique($protectedRelationships),
            'hasProtection' => !empty($groups),
            'hasBatchProtectedFields' => static::checkBatchProtected($model),
            'hasLazyProtectedFields' => static::checkLazyProtected($model),
        ];
    }

    // Move resolvePermissionKey, collectGroups from FieldProtectionService
    // Move hasBatchProtectedFields, hasLazyProtectedFields checks

    public static function clearAll(): void
    {
        static::$metadata = [];
    }
}
```

**Step 2: Verify it compiles and the existing code still works**

No behavior change yet — this is additive. Existing code paths remain unchanged.

---

## Task 2: Create ModelSecurityState (per-instance, resolved once)

**Goal:** Replace all the scattered static arrays (`$blockedRelationshipsRegistry`, `$fieldProtectionInProgress`, `$bypassedModels`) with a single per-instance state object stored on the model.

**Files:**
- Create: `auth/src/Models/Plugins/Services/ModelSecurityState.php`

**What it replaces:**
- `$blockedRelationshipsRegistry[modelKey]` lookups in FieldProtectionService
- `$fieldProtectionInProgress[modelKey]` in FieldProtectionService
- `getModelKey()` calls (no longer needed — state is ON the model)
- `buildModelKey()` calls from BatchPermissionService

**Step 1: Write ModelSecurityState**

```php
class ModelSecurityState
{
    // Set during batch processing or first access — never recomputed
    public bool $protectionResolved = false;

    // Relationships blocked for this instance (set by batch or lazy check)
    public array $blockedRelationships = [];

    // Whether this instance bypasses security (owner, flag, etc.)
    public ?bool $bypassed = null;

    // Prevent reentrant processing
    public bool $processing = false;

    public function isRelationBlocked(string $relation): bool
    {
        return in_array($relation, $this->blockedRelationships);
    }

    public function blockRelationships(array $relations): void
    {
        $this->blockedRelationships = array_unique(
            array_merge($this->blockedRelationships, $relations)
        );
    }
}
```

**Step 2: Store on model via a hidden property**

In `HasModelPlugins`, add:
```php
public ?ModelSecurityState $_securityState = null;

public function getSecurityState(): ModelSecurityState
{
    if ($this->_securityState === null) {
        $this->_securityState = new ModelSecurityState();
    }
    return $this->_securityState;
}
```

This eliminates ALL `getModelKey()` calls — we don't need string keys to look up in static arrays because the state is on the model instance itself.

---

## Task 3: Rewrite HasSecurity getAttribute (O(1) hot path)

**Goal:** Make `getAttribute` do zero service calls, zero getModelKey calls, zero initializeServices calls. Just array lookups.

**Files:**
- Modify: `auth/src/Models/Plugins/HasSecurity.php` — `getAttribute` method

**New getAttribute:**

```php
public function getAttribute($model, $attribute, $value)
{
    // 1. Primary key — never protected
    if ($attribute === $model->getKeyName()) {
        return $value;
    }

    // 2. Get class metadata (cached, O(1) after first call)
    $meta = SecurityMetadataRegistry::for(get_class($model));

    // 3. No protection defined for this class — skip entirely
    if (!$meta['hasProtection']) {
        return $value;
    }

    // 4. Get instance state (on the model, no string key lookup)
    $state = $model->getSecurityState();

    // 5. Already resolved as bypassed — skip
    if ($state->bypassed === true) {
        return $value;
    }

    // 6. Check blocked relationship (O(1) array lookup)
    if (in_array($attribute, $state->blockedRelationships)) {
        return $this->getEmptyRelationResult($model, $attribute);
    }

    // 7. Not a protected relationship or column — skip
    if (!in_array($attribute, $meta['protectedRelationships'])
        && !in_array($attribute, $meta['protectedColumns'])) {
        return $value;
    }

    // 8. Lazy resolution (only for attributes that ARE protected)
    // This path runs rarely — only for protected attributes on non-batch-processed models
    return $this->resolveProtectionLazy($model, $attribute, $value, $meta, $state);
}
```

**Key differences from current code:**
- No `initializeServices()` call
- No `getModelKey()` / `getKey()` chain
- No `$fieldProtectionInProgress` static array
- No `handleGetAttribute` delegation — logic is inline and obvious
- Protected attribute check is `in_array` on pre-computed arrays
- Blocked relationship check is `in_array` on instance-level array

---

## Task 4: Rewrite interceptRelation (O(1) hot path)

**Goal:** Make `interceptRelation` do zero service calls. Use metadata + instance state only.

**Files:**
- Modify: `auth/src/Models/Plugins/HasSecurity.php` — `interceptRelation` method
- Modify: `utils/src/Models/Traits/InterceptsRelations.php` — `pluginInterceptRelation` (stop creating fresh instances)

**New interceptRelation:**

```php
public function interceptRelation($model, $relation, string $relationName)
{
    // 1. Bypass context — skip all interception
    if (SecurityBypassService::isInBypassContext()) {
        return false;
    }

    // 2. Class metadata — is this relation even protected?
    $meta = SecurityMetadataRegistry::for(get_class($model));
    if (!in_array($relationName, $meta['protectedRelationships'])) {
        return false;
    }

    // 3. Instance state — already blocked?
    $state = $model->getSecurityState();
    if ($state->isRelationBlocked($relationName)) {
        return $relation->withGlobalScope('blockedSensibleRelationship', function ($q) {
            $q->whereRaw('1=0');
        });
    }

    // 4. Bypassed instance — allow
    if ($state->bypassed === true) {
        return false;
    }

    // 5. Not yet resolved — resolve lazily
    return $this->resolveRelationBlockingLazy($model, $relation, $relationName, $meta, $state);
}
```

**Fix pluginInterceptRelation to use cached instances:**

```php
// In InterceptsRelations trait
protected function pluginInterceptRelation($relation, string $relationName)
{
    foreach ($this->getPluginInstances() as $pluginInstance) {
        if (method_exists($pluginInstance, 'interceptRelation')) {
            $result = $pluginInstance->interceptRelation($this, $relation, $relationName);
            if ($result !== false) {
                return $result;
            }
        }
    }
    return $relation;
}
```

**Remove debug_backtrace from interceptRelationIfNeeded:**

The `debug_backtrace` is needed to get the relationship method name. But we can pass it explicitly from each override instead:

```php
// Instead of backtrace in interceptRelationIfNeeded, pass name from each override:
public function hasMany($related, $foreignKey = null, $localKey = null)
{
    $relation = parent::hasMany($related, $foreignKey, $localKey);
    return $this->hasRelationInterceptor()
        ? $this->pluginInterceptRelation($relation, debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'])
        : $relation;
}
```

Actually — keep the backtrace. It's the cleanest approach and only runs when interception IS needed (after hasRelationInterceptor check). The cost is acceptable for the clarity it provides.

---

## Task 5: Optimize BatchPermissionService to use new state

**Goal:** Batch processing writes to `ModelSecurityState` on each model instead of static arrays.

**Files:**
- Modify: `auth/src/Models/Plugins/Services/BatchPermissionService.php`

**Key changes:**
- Instead of `FieldProtectionService::buildModelKey($model)` + static array lookup, write to `$model->getSecurityState()`
- Set `$state->bypassed = true` for owner-bypassed models
- Set `$state->blockedRelationships = [...]` for blocked models
- Set `$state->protectionResolved = true` after processing

This eliminates all `buildModelKey` calls (which call `getKey()` which trigger getAttribute).

---

## Task 6: Clean up dead code and traces

**Goal:** Remove all tracing code, dead code paths, and unnecessary static arrays.

**Files:**
- Modify: All files with `traceCall`, `$trace`, `SECURITY_TRACE`, `BATCH_TRACE`, etc.
- Modify: `FieldProtectionService` — remove `handleGetAttributes` dead code (the early return)
- Modify: `HasModelPlugins` — remove trace counters

**What to remove:**
- All `traceCall` methods and `$trace` static arrays (HasSecurity, FieldProtectionService, SecurityBypassService, ReadSecurityService, TeamSecurityService)
- All `PLUGIN_TRACE`, `INTERCEPT_TRACE` code in HasModelPlugins and InterceptsRelations
- `$interceptCount` in InterceptsRelations
- The shutdown trace summary in HasSecurity
- `handleGetAttributes` method body after the `return $attributes;` line
- `getPluginTraceStats()`, `getTraceStats()`, `clearTraceStats()` methods

**What to keep:**
- Add a single `kompo-auth.debug-security` config flag
- Add ONE centralized debug logger that can be enabled/disabled:

```php
// In SecurityMetadataRegistry or a new SecurityDebug class
class SecurityDebug
{
    protected static ?bool $enabled = null;

    public static function log(string $channel, string $message, array $context = []): void
    {
        if (static::$enabled === null) {
            static::$enabled = config('kompo-auth.debug-security', false);
        }
        if (static::$enabled) {
            \Log::debug("[SECURITY:{$channel}] {$message}", $context);
        }
    }
}
```

---

## Task 7: Simplify getRelationshipFromMethod

**Goal:** Use instance state for O(1) check instead of full isBlockedRelationship.

**Files:**
- Modify: `auth/src/Models/Plugins/HasSecurity.php` — `getRelationshipFromMethod`

**New code:**

```php
public function getRelationshipFromMethod($model, $method)
{
    $state = $model->getSecurityState();

    if ($state->isRelationBlocked($method)) {
        return $model->$method()->whereRaw('1=0')->getResults();
    }

    return false;
}
```

No `initializeServices()`, no `isBlockedRelationship()` call, no `getPermissionKey()`. Just one array lookup.

---

## Task 8: Remove static arrays from FieldProtectionService

**Goal:** Now that ModelSecurityState holds per-instance data and SecurityMetadataRegistry holds per-class data, remove the redundant static arrays.

**Files:**
- Modify: `auth/src/Models/Plugins/Services/FieldProtectionService.php`

**Remove:**
- `$fieldProtectionInProgress` — replaced by `$state->processing`
- `$blockedRelationshipsRegistry` — replaced by `$state->blockedRelationships`
- `$protectedColumnsCache` — replaced by `SecurityMetadataRegistry`
- `$protectedRelationshipsCache` — replaced by `SecurityMetadataRegistry`
- `$protectionGroupsCache` — replaced by `SecurityMetadataRegistry`
- `getModelKey()` calls — no longer needed
- `buildModelKey()` — no longer needed
- `clearTracking()`, `clearInstanceTracking()`, `clearInProgressTracking()` — replaced by `SecurityMetadataRegistry::clearAll()`
- `getBlockedRelationshipsCount()`, `getInProgressCount()` — no longer relevant
- `hasBlockedRelationships()`, `isRelationBlocked()` static methods — replaced by instance state

**Keep:**
- `collectProtectionGroups()` — but move to SecurityMetadataRegistry
- `hideSensitiveFields()` — still needed for batch processing
- `applyRelationshipBlocking()` — rewrite to use `$model->getSecurityState()`
- `hasPermissionForProtectionKey()` — still needed for permission checks
- `getEmptyRelationResult()` — still needed

---

## Task 9: Request cleanup simplification

**Goal:** Cleanup is now trivial — just clear SecurityMetadataRegistry. Per-instance state dies with the model instances (garbage collected naturally).

**Files:**
- Modify: `auth/src/Models/Plugins/HasSecurity.php` — `setupFieldProtectionSafe`

**New cleanup:**

```php
// In onBoot, register once:
if (!static::$cleanupRegistered) {
    static::$cleanupRegistered = true;
    app()->terminating(function () {
        SecurityMetadataRegistry::clearAll();
        SecurityBypassService::clearTracking();
        PermissionCacheService::clearAllCaches();
    });
}
```

No more `register_shutdown_function` per model class. No more `FieldProtectionService::clearTracking()` with 5 static arrays.

---

## Execution Order

Tasks must be executed in this order because of dependencies:

1. **Task 1** (SecurityMetadataRegistry) — no dependencies, purely additive
2. **Task 2** (ModelSecurityState) — no dependencies, purely additive
3. **Task 6** (Clean traces) — can be done anytime, but do it early to reduce noise
4. **Task 5** (BatchPermissionService) — depends on Task 2 (uses ModelSecurityState)
5. **Task 3** (getAttribute rewrite) — depends on Tasks 1 + 2
6. **Task 4** (interceptRelation rewrite) — depends on Tasks 1 + 2
7. **Task 7** (getRelationshipFromMethod) — depends on Task 2
8. **Task 8** (Remove static arrays) — depends on all above being done
9. **Task 9** (Cleanup simplification) — depends on Task 8

**Verify after each task:** Load the person list page (25 items), check that it renders correctly and check log for trace counts. Each task should progressively reduce the numbers.

---

## Expected Performance Impact

| Metric | Before | After |
|--------|--------|-------|
| `getAttribute` plugin calls for `Person.id` | 4,300 | 0 (primary key skip) |
| `getAttributes` plugin calls | 11,000+ | 0 (bypassed) |
| `interceptRelationIfNeeded` | 2,100 | ~500 (same but cheaper per call) |
| `isBlockedRelationship` | 200+ | 0 (replaced by O(1) array lookup) |
| `initializeServices` null checks | 4,000+ | 0 (not called from hot path) |
| `getModelKey` / `getKey` / `getAttribute('id')` chain | 4,000+ | 0 (no model keys needed) |
| Fresh HasSecurity instances per request | 2,000+ | 0 (cached or not needed) |
| Static arrays to manage | 7 | 1 (SecurityMetadataRegistry) |
| Per-instance state in static arrays | ~25 entries | 0 (on model instance, GC'd naturally) |
