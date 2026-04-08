<?php

namespace Kompo\Auth\Models\Plugins\Services;

use Kompo\Auth\Models\Teams\Permission;

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

        return [
            'permissionKey' => $permissionKey,
            'groups' => $groups,
            'protectedColumns' => $protectedColumns,         // ['col' => 0, ...] for isset()
            'protectedRelationships' => $protectedRelationships, // ['rel' => 0, ...] for isset()
            'hasProtection' => $hasProtection,
            'hasBatchProtectedFields' => static::checkBatchProtectedFields($model),
            'hasLazyProtectedFields' => static::checkLazyProtectedFields($model),
        ];
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
            'hasBatchProtectedFields' => false,
            'hasLazyProtectedFields' => false,
        ];
    }

    /**
     * Resolve the permission key using 3-step resolution:
     * 1. Check if model has getPermissionKey() method
     * 2. Check if model has $permissionKey property
     * 3. Fall back to class_basename()
     */
    protected static function resolvePermissionKey($model, string $modelClass): string
    {
        try {
            if (method_exists($modelClass, 'getPermissionKey')) {
                return $model->getPermissionKey();
            }

            if (property_exists($modelClass, 'permissionKey')) {
                return getPrivateProperty($model, 'permissionKey');
            }
        } catch (\Throwable $e) {
            // Fall through to default
        }

        return class_basename($modelClass);
    }

    /**
     * Collect ALL protection groups from all 5 sources:
     * 1. sensibleColumns (flat array)
     * 2. sensibleColumnsGroups (named groups)
     * 3. sensibleRelationships (flat array)
     * 4. sensibleRelationshipsGroups (named groups)
     * 5. DB-discovered groups (opt-in via $discoverSensibleFromDb)
     */
    protected static function collectAllProtectionGroups($model, string $basePermissionKey): array
    {
        $groups = [];

        // 1. sensibleColumns -> one group
        $sensibleColumns = static::getPropertySafely($model, 'sensibleColumns');
        if (!empty($sensibleColumns)) {
            $groups[] = [
                'key' => static::resolveSensibleColumnsPermissionKey($model, $basePermissionKey),
                'fields' => $sensibleColumns,
                'type' => 'columns',
            ];
        }

        // 2. sensibleColumnsGroups -> one group per entry
        $columnsGroups = static::getPropertySafely($model, 'sensibleColumnsGroups');
        if (is_array($columnsGroups)) {
            foreach ($columnsGroups as $groupName => $fields) {
                if (!empty($fields)) {
                    $groups[] = [
                        'key' => $basePermissionKey . '.sensibleColumnsGroups.' . $groupName,
                        'fields' => $fields,
                        'type' => 'columns',
                    ];
                }
            }
        }

        // 3. sensibleRelationships -> one group
        $sensibleRelationships = static::getPropertySafely($model, 'sensibleRelationships');
        if (!empty($sensibleRelationships)) {
            $groups[] = [
                'key' => static::resolveSensibleRelationshipsPermissionKey($model, $basePermissionKey),
                'fields' => $sensibleRelationships,
                'type' => 'relationships',
            ];
        }

        // 4. sensibleRelationshipsGroups -> one group per entry
        $relationshipsGroups = static::getPropertySafely($model, 'sensibleRelationshipsGroups');
        if (is_array($relationshipsGroups)) {
            foreach ($relationshipsGroups as $groupName => $fields) {
                if (!empty($fields)) {
                    $groups[] = [
                        'key' => $basePermissionKey . '.sensibleRelationshipsGroups.' . $groupName,
                        'fields' => $fields,
                        'type' => 'relationships',
                    ];
                }
            }
        }

        // 5. DB-discovered groups (opt-in)
        if (static::shouldDiscoverFromDb($model)) {
            $groups = array_merge($groups, static::discoverDbProtectionGroups($basePermissionKey));
        }

        return $groups;
    }

    /**
     * Resolve the sensible columns permission key using 3-step resolution:
     * 1. getSensibleColumnsPermissionKey() method
     * 2. $sensibleColumnsPermissionKey property
     * 3. $basePermissionKey . '.sensibleColumns'
     */
    protected static function resolveSensibleColumnsPermissionKey($model, string $basePermissionKey): string
    {
        try {
            if (method_exists($model, 'getSensibleColumnsPermissionKey')) {
                return $model->getSensibleColumnsPermissionKey();
            }

            if (property_exists($model, 'sensibleColumnsPermissionKey')) {
                return getPrivateProperty($model, 'sensibleColumnsPermissionKey');
            }
        } catch (\Throwable $e) {
            // Fall through to default
        }

        return $basePermissionKey . '.sensibleColumns';
    }

    /**
     * Resolve the sensible relationships permission key using 3-step resolution:
     * 1. getSensibleRelationshipsPermissionKey() method
     * 2. $sensibleRelationshipsPermissionKey property
     * 3. $basePermissionKey . '.sensibleRelationships'
     */
    protected static function resolveSensibleRelationshipsPermissionKey($model, string $basePermissionKey): string
    {
        try {
            if (method_exists($model, 'getSensibleRelationshipsPermissionKey')) {
                return $model->getSensibleRelationshipsPermissionKey();
            }

            if (property_exists($model, 'sensibleRelationshipsPermissionKey')) {
                return getPrivateProperty($model, 'sensibleRelationshipsPermissionKey');
            }
        } catch (\Throwable $e) {
            // Fall through to default
        }

        return $basePermissionKey . '.sensibleRelationships';
    }

    /**
     * Check if the model opts in to DB-based group discovery.
     */
    protected static function shouldDiscoverFromDb($model): bool
    {
        try {
            return getPrivateProperty($model, 'discoverSensibleFromDb') === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Discover protection groups from the database by querying the Permission table
     * for matching sensibleColumns.* and sensibleRelationships.* patterns.
     */
    protected static function discoverDbProtectionGroups(string $basePermissionKey): array
    {
        $groups = [];

        try {
            // Discover column-level permissions: Person.sensibleColumns.*
            $columnPrefix = $basePermissionKey . '.sensibleColumns.';
            $columnPermissions = Permission::where('permission_key', 'like', $columnPrefix . '%')->get();

            foreach ($columnPermissions as $permission) {
                $columnName = str_replace($columnPrefix, '', $permission->permission_key);
                $groups[] = [
                    'key' => $permission->permission_key,
                    'fields' => [$columnName],
                    'type' => 'columns',
                ];
            }

            // Discover relationship-level permissions: Person.sensibleRelationships.*
            $relationPrefix = $basePermissionKey . '.sensibleRelationships.';
            $relationPermissions = Permission::where('permission_key', 'like', $relationPrefix . '%')->get();

            foreach ($relationPermissions as $permission) {
                $relationName = str_replace($relationPrefix, '', $permission->permission_key);
                $groups[] = [
                    'key' => $permission->permission_key,
                    'fields' => [$relationName],
                    'type' => 'relationships',
                ];
            }
        } catch (\Throwable $e) {
            // DB not available or Permission table doesn't exist yet — skip discovery
        }

        return $groups;
    }

    /**
     * Check if the model uses batch protected fields mode.
     */
    protected static function checkBatchProtectedFields($model): bool
    {
        try {
            return getPrivateProperty($model, 'batchProtectedFields') === true
                || config('kompo-auth.security.batch-protected-fields');
        } catch (\Throwable $e) {
            return (bool) config('kompo-auth.security.batch-protected-fields');
        }
    }

    /**
     * Check if the model uses lazy protected fields mode.
     */
    protected static function checkLazyProtectedFields($model): bool
    {
        try {
            return getPrivateProperty($model, 'lazyProtectedFields') === true
                || config('kompo-auth.security.lazy-protected-fields');
        } catch (\Throwable $e) {
            return (bool) config('kompo-auth.security.lazy-protected-fields');
        }
    }

    /**
     * Safely get a private/protected property from a model via getPrivateProperty().
     * Returns the property value if it exists and is an array, otherwise null.
     */
    protected static function getPropertySafely($model, string $property)
    {
        try {
            if (!property_exists($model, $property)) {
                return null;
            }

            $value = getPrivateProperty($model, $property);

            return is_array($value) ? $value : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
