<?php

namespace Kompo\Auth\Teams\Security;

use Illuminate\Support\Facades\Log;
use Kompo\Auth\Contracts\Security\EnforcesStrictPermissions;
use Condoedge\Utils\Contracts\Security\HasOwnedRecords;
use Kompo\Auth\Contracts\Security\HasPermissionKey;
use Kompo\Auth\Contracts\Security\HasProtectedFields;
use Kompo\Auth\Contracts\Security\NoTeamScope;
use Kompo\Auth\Contracts\Security\OptsOutOfSecurity;
use Condoedge\Utils\Contracts\Security\ScopedToTeam;

/**
 * SecurityMetadataRegistry
 *
 * A static registry that computes and caches class-level security metadata ONCE per model class.
 * This eliminates redundant reflection, property access, and DB queries that previously occurred
 * on every attribute access (15,000+ calls per page load).
 *
 * All data stored here is class-level (not instance-level) and immutable for the request lifetime.
 *
 * Usage:
 *   $meta = SecurityMetadataRegistry::for(App\Models\Person::class);
 *   if ($meta['hasProtection'] && in_array($attr, $meta['protectedColumns'])) { ... }
 */
class SecurityMetadataRegistry
{
    /**
     * Cached metadata per model class.
     * Format: ['App\Models\Person' => ['permissionKey' => ..., 'groups' => [...], ...]]
     */
    protected static $cache = [];

    /**
     * Get cached security metadata for a model class.
     * Computes on first call, returns from cache on subsequent calls.
     */
    public static function for(string $modelClass): array
    {
        if (isset(static::$cache[$modelClass])) {
            return static::$cache[$modelClass];
        }

        static::$cache[$modelClass] = static::compute($modelClass);

        return static::$cache[$modelClass];
    }

    /**
     * Clear all cached metadata. Call at request end to free memory.
     */
    public static function clearAll(): void
    {
        static::$cache = [];
    }

    /**
     * Check if metadata has been computed for a given class (useful for testing/debugging).
     */
    public static function has(string $modelClass): bool
    {
        return isset(static::$cache[$modelClass]);
    }

    /**
     * Compute all class-level security metadata for a model class.
     * Creates ONE model instance for reflection, extracts everything needed,
     * then discards the instance.
     */
    protected static function compute(string $modelClass): array
    {
        try {
            $model = new $modelClass;
        } catch (\Throwable $e) {
            return static::emptyMetadata();
        }

        $permissionKey = static::resolvePermissionKey($model, $modelClass);
        $groups = static::collectAllProtectionGroups($model, $permissionKey);

        // Pre-compute flat arrays for O(1) lookup
        $protectedColumns = [];
        $protectedRelationships = [];

        foreach ($groups as $group) {
            if ($group['type'] === 'columns') {
                $protectedColumns = array_merge($protectedColumns, $group['fields']);
            } elseif ($group['type'] === 'relationships') {
                $protectedRelationships = array_merge($protectedRelationships, $group['fields']);
            }
        }

        // Store as associative arrays for O(1) isset() lookups
        $protectedColumns = array_flip(array_unique($protectedColumns));
        $protectedRelationships = array_flip(array_unique($protectedRelationships));

        $hasProtection = !empty($groups);

        $usesScopedToTeam = $model instanceof ScopedToTeam;
        $optedOutOfTeamScope = $model instanceof NoTeamScope;
        $usesHasOwnedRecords = $model instanceof HasOwnedRecords;

        $autoTeamIdColumn = null;
        if (!$usesScopedToTeam && !$optedOutOfTeamScope) {
            $autoTeamIdColumn = static::resolveAutoTeamIdColumn($model, $modelClass);
        }

        $autoUserIdColumn = null;
        if (!$usesHasOwnedRecords) {
            $autoUserIdColumn = static::resolveAutoUserIdColumn($model, $modelClass);
        }

        $optsOutOfSecurity = $model instanceof OptsOutOfSecurity;

        // Loud fallback — fires once per class per request because the
        // registry caches the result. Suppressible by NoTeamScope.
        if (!$usesScopedToTeam && !$optedOutOfTeamScope && $autoTeamIdColumn !== null
            && kompoAuthSecurityConfig('warn_on_missing_team_contract', true)) {
            \Cache::remember(
                "kompo-auth:warned_team_scope:$modelClass",
                3600 * 24,
                function () use ($modelClass, $autoTeamIdColumn) {
                    Log::warning(sprintf(
                        '[kompo-auth] %s has a `%s` column but does not implement ScopedToTeam — '
                        . 'auto-detecting team scope. Declare `implements ScopedToTeam` (or '
                        . '`NoTeamScope` to opt out) to silence this warning.',
                        $modelClass,
                        $autoTeamIdColumn,
                    ));
            });
        }

        if (!$usesHasOwnedRecords && $autoUserIdColumn !== null
            && kompoAuthSecurityConfig('warn_on_missing_owned_records_contract', true)) {
            \Cache::remember(
                "kompo-auth:warned_owned_records:$modelClass",
                3600 * 24,
                function () use ($modelClass, $autoUserIdColumn) {
                    Log::warning(sprintf(
                        '[kompo-auth] %s has a `%s` column but does not implement HasOwnedRecords — '
                        . 'auto-detecting owner-bypass. Declare `implements HasOwnedRecords + use OwnedByUserIdColumn` '
                        . 'to silence this warning.',
                        $modelClass,
                        $autoUserIdColumn,
                    ));
            });
        }

        // No scoping path at all and not explicitly opted out — surface so
        // unscoped models don't slip through silently. Opt-in (default off)
        // because legitimate non-scoped models exist (lookup tables, etc.).
        $noTeamScopePath  = !$usesScopedToTeam && $autoTeamIdColumn === null && !$optedOutOfTeamScope && !$optsOutOfSecurity;
        $noOwnerScopePath = !$usesHasOwnedRecords && $autoUserIdColumn === null && !$optsOutOfSecurity;
        if (($noTeamScopePath || $noOwnerScopePath)
            && kompoAuthSecurityConfig('error_on_unscoped_models', false)) {
            \Cache::remember(
                "kompo-auth:warned_unscoped:$modelClass",
                3600 * 24,,
                function () use ($modelClass, $noTeamScopePath, $noOwnerScopePath) {
                    Log::error(sprintf(
                        '[kompo-auth] %s has no scoping path (team: %s, owner: %s). '
                        . 'Records are visible to anyone passing permission checks. '
                        . 'Declare ScopedToTeam/HasOwnedRecords, add a team_id/user_id column, '
                        . 'or implement NoTeamScope/OptsOutOfSecurity to silence.',
                        $modelClass,
                        $noTeamScopePath ? 'missing' : 'ok',
                        $noOwnerScopePath ? 'missing' : 'ok',
                    ));
            });
        }

        return [
            'permissionKey' => $permissionKey,
            'groups' => $groups,
            'protectedColumns' => $protectedColumns,
            'protectedRelationships' => $protectedRelationships,
            'hasProtection' => $hasProtection,
            'hasCustomBypassMethod' => method_exists($modelClass, 'isSecurityBypassRequired'),
            'usesScopedToTeamContract' => $usesScopedToTeam,
            'optedOutOfTeamScope' => $optedOutOfTeamScope,
            // Set when the model lacks ScopedToTeam but has a `team_id` column.
            // ReadSecurityService falls back to `whereIn('team_id', $teamIds)`.
            'autoTeamIdColumn' => $autoTeamIdColumn,
            'usesHasOwnedRecordsContract' => $usesHasOwnedRecords,
            // Set when the model lacks HasOwnedRecords but has a `user_id`
            // column. OwnedRecordsResolver falls back to `where('user_id', ...)`.
            'autoUserIdColumn' => $autoUserIdColumn,
            'usesHasProtectedFieldsContract' => $model instanceof HasProtectedFields,
            'enforcesStrictPermissions' => $model instanceof EnforcesStrictPermissions,
            'skippedOperations' => static::resolveSkippedOperations($model),
        ];
    }

    protected static function resolveAutoTeamIdColumn($model, string $modelClass): ?string
    {
        try {
            $table = $model->getTable();
            if (hasColumnCached($table, 'team_id')) {
                return 'team_id';
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return null;
    }

    protected static function resolveAutoUserIdColumn($model, string $modelClass): ?string
    {
        try {
            $table = $model->getTable();
            if (hasColumnCached($table, 'user_id')) {
                return 'user_id';
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return null;
    }

    /**
     * @return list<'read'|'write'|'delete'>
     */
    protected static function resolveSkippedOperations($model): array
    {
        if ($model instanceof OptsOutOfSecurity) {
            return array_values(array_intersect(
                $model->getSkippedSecurityOperations(),
                ['read', 'write', 'delete'],
            ));
        }

        return [];
    }

    /**
     * Return empty metadata for models that cannot be instantiated or have no protection.
     */
    protected static function emptyMetadata(): array
    {
        return [
            'permissionKey' => '',
            'groups' => [],
            'protectedColumns' => [],
            'protectedRelationships' => [],
            'hasProtection' => false,
            'hasCustomBypassMethod' => false,
            'usesScopedToTeamContract' => false,
            'optedOutOfTeamScope' => false,
            'autoTeamIdColumn' => null,
            'autoUserIdColumn' => null,
            'usesHasOwnedRecordsContract' => false,
            'usesHasProtectedFieldsContract' => false,
            'enforcesStrictPermissions' => false,
            'skippedOperations' => [],
        ];
    }

    /**
     * HasPermissionKey contract → class_basename. No legacy property / method.
     */
    protected static function resolvePermissionKey($model, string $modelClass): string
    {
        try {
            if ($model instanceof HasPermissionKey) {
                return $model->getPermissionKey();
            }
        } catch (\Throwable $e) {
            // Fall through to default
        }

        return class_basename($modelClass);
    }

    /**
     * Protection groups come from `HasProtectedFields`. Models that need to
     * read the legacy `$sensibleColumns` / `$sensibleRelationships`
     * declarations should `use WithSimpleProtection` (which implements the
     * contract over those properties).
     */
    protected static function collectAllProtectionGroups($model, string $basePermissionKey): array
    {
        if ($model instanceof HasProtectedFields) {
            return static::normalizeProtectionGroups($model->getProtectionGroups());
        }

        return [];
    }

    /**
     * Validate / coerce HasProtectedFields output into the shape consumers expect.
     * Drops entries with missing keys, empty field lists, or invalid type.
     *
     * @param  list<array{key?: string, fields?: list<string>, type?: string}>  $groups
     * @return list<array{key: string, fields: list<string>, type: 'columns'|'relationships'}>
     */
    protected static function normalizeProtectionGroups(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            $key = $g['key'] ?? null;
            $fields = $g['fields'] ?? null;
            $type = $g['type'] ?? null;

            if (!is_string($key) || $key === '' || !is_array($fields) || empty($fields)) {
                continue;
            }
            if ($type !== 'columns' && $type !== 'relationships') {
                continue;
            }

            $out[] = [
                'key'    => $key,
                'fields' => array_values($fields),
                'type'   => $type,
            ];
        }
        return $out;
    }

}
