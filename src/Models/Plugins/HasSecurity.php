<?php

namespace Kompo\Auth\Models\Plugins;

use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\Roles\PermissionException;
use Condoedge\Utils\Models\Plugins\ModelPlugin;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * HasSecurity Plugin
 * 
 * Provides automated security enforcement at the model level.
 * When applied to a model, this plugin restricts operations (read/write/delete) based on user permissions.
 *
 * Security Flow:
 * 1. READ: Automatically filters query results via global scope
 * 2. WRITE: Validates permissions before saving
 * 3. DELETE: Validates permissions before deleting
 * 4. Field Protection: Hides sensitive fields from unauthorized users
 *
 * The model can configure security behavior through properties:
 * - $readSecurityRestrictions (boolean)
 * - $saveSecurityRestrictions (boolean)
 * - $deleteSecurityRestrictions (boolean)
 * - $restrictByTeam (boolean)
 * - $sensibleColumns (array)
 * 
 * Ownership bypass methods:
 * - scopeUserOwnedRecords - Query scope to identify records owned by the current user
 * - usersIdsAllowedToManage - Method returning user IDs with ownership-like access
 */
class HasSecurity extends ModelPlugin
{
    /**
     * Tracks models that have bypassed security for the current request.
     * This prevents having to check bypass status multiple times.
     */
    protected static $bypassedModels = [];

    /**
     * Bootstrap the security features when the model is booted.
     * Sets up global scopes and event listeners to enforce permissions.
     */
    public function onBoot()
    {
        // If security is globally disabled, exit early
        if ($this->isSecurityGloballyBypassed()) {
            $this->modelClass::saving(function ($model) {
                $this->markModelAsBypassed($model);
            });

            $this->modelClass::deleting(function ($model) {
                $this->markModelAsBypassed($model);
            });

            return;
        }

        $modelClass = $this->modelClass;

        // Apply READ security
        $this->setupReadSecurity($modelClass);
        
        // Apply WRITE security
        $this->setupWriteSecurity($modelClass);
        
        // Apply DELETE security
        $this->setupDeleteSecurity($modelClass);
        
        // Apply FIELD PROTECTION
        $this->setupFieldProtection($modelClass);
        
        // Setup cleanup events
        $this->setupCleanupEvents($modelClass);
    }

    /**
     * Checks if security is globally bypassed in config.
     */
    protected function isSecurityGloballyBypassed()
    {   
        return globalSecurityBypass();
    }

    /**
     * Sets up read security with global scope.
     * 
     * @param string $modelClass The model class
     */
    protected function setupReadSecurity($modelClass)
    {
        if ($this->hasReadSecurityRestrictions() && Permission::findByKey($this->getPermissionKey())) {
            $modelClass::addGlobalScope('authUserHasPermissions', function ($builder) {
                $this->applyReadSecurityScope($builder);
            });
        }
    }

    /**
     * Applies the read security scope to a query builder.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $builder The query builder
     */
    protected function applyReadSecurityScope($builder)
    {
        // Check if model has a method to identify user-owned records
        $hasUserOwnedRecordsScope = $this->modelHasMethod('scopeUserOwnedRecords');

        // For models not restricted by team
        if (!$this->massRestrictByTeam()) {
            $this->applyNonTeamReadSecurity($builder, $hasUserOwnedRecordsScope);
        } else {
            // For team-restricted models
            $this->applyTeamReadSecurity($builder, $hasUserOwnedRecordsScope);
        }
    }

    /**
     * Applies read security for non-team-restricted models.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $builder The query builder
     * @param bool $hasUserOwnedRecordsScope Whether the model has user ownership scope
     */
    protected function applyNonTeamReadSecurity($builder, $hasUserOwnedRecordsScope)
    {
        if (!auth()->user()?->hasPermission($this->getPermissionKey(), PermissionTypeEnum::READ)) {
            $builder->when($hasUserOwnedRecordsScope, function ($q) {
                // Allow access to user's own records
                $q->userOwnedRecords();
            })->when(!$hasUserOwnedRecordsScope, function ($q) {
                if (Schema::hasColumn((new ($this->modelClass))->getTable(), 'user_id')) {
                    // Allow access to user's own records if user_id column exists
                    $q->where('user_id', auth()->user()?->id);
                }
            });
        }
    }

    /**
     * Applies read security for team-restricted models.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $builder The query builder
     * @param bool $hasUserOwnedRecordsScope Whether the model has user ownership scope
     */
    protected function applyTeamReadSecurity($builder, $hasUserOwnedRecordsScope) 
    {
        // Get teams where user has access
        $teamIds = auth()->user()?->getTeamsIdsWithPermission(
            $this->getPermissionKey(), 
            PermissionTypeEnum::READ
        );

        $builder->where(function($q) use ($teamIds, $hasUserOwnedRecordsScope) {
            if ($this->modelHasMethod('scopeSecurityForTeams')) {
                // Custom team security logic
                $q->securityForTeams($teamIds);
            } else if($teamIdCol = $this->getTeamIdColumn()) {
                // Standard team column filter
                $q->whereIn($teamIdCol, $teamIds);
            }

            // Allow access to user's own records regardless of team
            if ($hasUserOwnedRecordsScope) {
                $q->orWhere(function($sq) {
                    $sq->userOwnedRecords();
                });
            } else {
                if (Schema::hasColumn((new ($this->modelClass))->getTable(), 'user_id')) {
                    // Allow access to user's own records if user_id column exists
                    $q->orWhere('user_id', auth()->user()?->id);
                }
            }
        });
    }

    /**
     * Sets up write security with events.
     * 
     * @param string $modelClass The model class
     */
    protected function setupWriteSecurity($modelClass)
    {
        $modelClass::saving(function ($model) {
            $this->handleSavingEvent($model);
        });
    }

    /**
     * Handles the saving event for a model.
     * 
     * @param mixed $model The model being saved
     */
    protected function handleSavingEvent($model)
    {
        // Skip security check if bypass is required
        if ($this->isSecurityBypassRequired($model)) {
            $this->markModelAsBypassed($model);
            return;
        }

        // Enforce write permissions if configured
        if ($this->hasSaveSecurityRestrictions()) {
            $this->checkWritePermissions($model);
        }
    }

    /**
     * Sets up delete security with events.
     * 
     * @param string $modelClass The model class
     */
    protected function setupDeleteSecurity($modelClass)
    {
        $modelClass::deleting(function ($model) {
            $this->handleDeletingEvent($model);
        });
    }

    /**
     * Handles the deleting event for a model.
     * 
     * @param mixed $model The model being deleted
     */
    protected function handleDeletingEvent($model)
    {
        // Skip security check if bypass is required
        if ($this->isSecurityBypassRequired($model)) {
            $this->markModelAsBypassed($model);
            return;
        }

        // Enforce delete permissions if configured
        if ($this->hasDeleteSecurityRestrictions()) {
            $this->checkWritePermissions($model);
        }
    }

    /**
     * Sets up cleanup events to manage the bypassed models tracking.
     * 
     * @param string $modelClass The model class
     */
    protected function setupCleanupEvents($modelClass)
    {
        $modelClass::deleted(function ($model) {
            unset(static::$bypassedModels[spl_object_hash($model)]);
        });

        $modelClass::saved(function ($model) {
            unset(static::$bypassedModels[spl_object_hash($model)]);
        });
    }

    /**
     * Sets up field protection with events.
     * 
     * @param string $modelClass The model class
     */
    protected function setupFieldProtection($modelClass)
    {
        $modelClass::retrieved(function ($model) {
            $this->handleRetrievedEvent($model);
        });
    }

    /**
     * Handles the retrieved event for a model.
     * Removes sensitive fields if user lacks permission.
     * 
     * @param mixed $model The retrieved model
     */
    protected function handleRetrievedEvent($model)
    {
        $sensibleColumnsKey = $this->getPermissionKey() . '.sensibleColumns';
        
        // Skip if no sensible columns permission exists
        if (!Permission::findByKey($sensibleColumnsKey)) {
            return;
        }

        // Skip if security bypass is required
        if ($this->isSecurityBypassRequired($model)) {
            return;
        }

        $this->removeSensitiveFields($model, $sensibleColumnsKey);
    }

    /**
     * Removes sensitive fields from a model if user lacks permission.
     * 
     * @param mixed $model The model
     * @param string $sensibleColumnsKey The permission key for sensitive columns
     */
    protected function removeSensitiveFields($model, $sensibleColumnsKey)
    {
        // Check if model has sensitive columns defined
        if (!$this->modelHasProperty($model, 'sensibleColumns')) {
            return;
        }

        $sensibleColumns = getPrivateProperty($model, 'sensibleColumns');

        // Get team context for team-specific permissions
        $teamsIdsRelated = $this->getTeamOwnersIds($model);

        // Remove sensitive fields if permission check fails
        if (!auth()->user()?->hasPermission($sensibleColumnsKey, PermissionTypeEnum::READ, $teamsIdsRelated)) {
            $model->setRawAttributes(
                array_diff_key($model->getRawOriginal(), array_flip($sensibleColumns))
            );
        }
    }

    /**
     * Marks a model as having bypassed security.
     * 
     * @param mixed $model The model
     */
    protected function markModelAsBypassed($model)
    {
        $model->offsetUnset('_bypassSecurity');
        static::$bypassedModels[spl_object_hash($model)] = true;
    }

    /**
     * Checks if a method exists on the model class.
     * 
     * @param string|object $modelOrClass The model or class name
     * @param string|null $method The method name (optional)
     * @return bool True if the method exists
     */
    protected function modelHasMethod($modelOrClass, $method = null)
    {
        if (is_string($modelOrClass) && is_string($method)) {
            return method_exists($modelOrClass, $method);
        } elseif (is_object($modelOrClass) && is_string($method)) {
            return method_exists($modelOrClass, $method);
        } else {
            return method_exists($this->modelClass, $modelOrClass);
        }
    }

    /**
     * Checks if a property exists on a model.
     * 
     * @param mixed $model The model
     * @param string $property The property name
     * @return bool True if the property exists
     */
    protected function modelHasProperty($model, $property)
    {
        return property_exists($model, $property);
    }

    /**
     * Verifies that the user has write permissions on a model.
     * 
     * @param mixed $model The model being checked
     * @throws PermissionException If user doesn't have required permissions
     * @return bool True if user has permission
     */
    public function checkWritePermissions($model = null)
    {
        if (!Permission::findByKey($this->getPermissionKey())) {
            return true;
        }

        // For non-team-restricted models
        if (!$this->individualRestrictByTeam($model) && 
            !auth()->user()?->hasPermission($this->getPermissionKey(), PermissionTypeEnum::WRITE)) {
            throw new PermissionException(__('permissions-you-do-not-have-write-permissions'));
        }

        // For team-restricted models
        if ($this->individualRestrictByTeam($model) && 
            !auth()->user()?->hasPermission($this->getPermissionKey(), PermissionTypeEnum::WRITE, $this->getTeamOwnersIds($model))) {
            throw new PermissionException(__('permissions-you-do-not-have-write-permissions'));
        }
        
        return true;
    }

    /**
     * Gets a relationship without security restrictions.
     * Useful for fetching related models that might otherwise be filtered by security.
     * 
     * @param mixed $model The model instance
     * @param string $method The relationship method name
     * @return mixed The relationship results
     * @throws LogicException If the method doesn't return a relationship
     */
    public function getRelationshipFromMethod($model, $method)
    {
        if (!$this->isSecurityBypassRequired($model)) {
            return false;
        }

        $relation = $model->$method()->withoutGlobalScope('authUserHasPermissions');

        if (!$relation instanceof Relation) {
            $this->throwInvalidRelationshipException($method);
        }

        return tap($relation->getResults(), function ($results) use ($method, $model) {
            $model->setRelation($method, $results);
        });
    }

    /**
     * Throws an exception for an invalid relationship.
     * 
     * @param string $method The method name
     * @throws LogicException Always
     */
    protected function throwInvalidRelationshipException($method)
    {
        throw new LogicException(sprintf(
            '%s::%s must return a relationship instance.', $this->modelClass, $method
        ));
    }

    /**
     * Determines if security checks should be bypassed for a model.
     * Multiple strategies are used to identify user's own records.
     * 
     * @param mixed $model The model to check
     * @return bool True if security should be bypassed
     */
    protected function isSecurityBypassRequired($model)
    {
        if ($this->hasBypassMethod($model)) {
            return true;
        }

        if ($this->hasBypassByUserId($model)) {
            return true;
        }

        if ($this->hasBypassByAllowlist($model)) {
            return true;
        }

        if ($this->hasBypassByScope($model)) {
            return true;
        }

        return $this->hasBypassByFlag($model);
    }

    /**
     * Checks if the model has a custom bypass method.
     * 
     * @param mixed $model The model
     * @return bool True if bypassed
     */
    protected function hasBypassMethod($model)
    {
        if (method_exists($model, 'isSecurityBypassRequired')) {
            return $model->securityHasBeenBypassed();
        }
        
        return false;
    }

    /**
     * Checks if bypass by user ID match applies.
     * 
     * @param mixed $model The model
     * @return bool True if bypassed
     */
    protected function hasBypassByUserId($model)
    {
        if ($model->getAttribute('user_id') && auth()->user()) {
            return $model->getAttribute('user_id') === auth()->user()->id;
        }
        
        return false;
    }

    /**
     * Checks if bypass by custom allowlist applies.
     * 
     * @param mixed $model The model
     * @return bool True if bypassed
     */
    protected function hasBypassByAllowlist($model)
    {
        if (method_exists($model, 'usersIdsAllowedToManage') && auth()->user()) {
            return collect($model->usersIdsAllowedToManage())->contains(auth()->user()->id);
        }
        
        return false;
    }

    /**
     * Checks if bypass by custom scope method applies.
     * 
     * @param mixed $model The model
     * @return bool True if bypassed
     */
    protected function hasBypassByScope($model)
    {
        if (method_exists($model, 'scopeUserOwnedRecords') && auth()->user()) {
            return $model->userOwnedRecords()->where('id', $model->id)->exists();
        }
        
        return false;
    }

    /**
     * Checks if bypass by explicit flag applies.
     * 
     * @param mixed $model The model
     * @return bool True if bypassed
     */
    protected function hasBypassByFlag($model)
    {
        return $model->getAttribute('_bypassSecurity') === true || 
               (static::$bypassedModels[spl_object_hash($model)] ?? false);
    }

    /**
     * Gets the permission key for the model.
     * By default, this is the class name without namespace.
     * 
     * @return string The permission key
     */
    protected function getPermissionKey()
    {
        return class_basename($this->modelClass);
    }

    /**
     * Determines if the model has read security restrictions.
     * 
     * @return bool True if read security is enabled
     */
    protected function hasReadSecurityRestrictions()
    {
        if (property_exists($this->modelClass, 'readSecurityRestrictions')) {
            return getPrivateProperty(new ($this->modelClass), 'readSecurityRestrictions');
        }

        return config('kompo-auth.security.default-read-security-restrictions', true);
    }

    /**
     * Determines if the model has delete security restrictions.
     * 
     * @return bool True if delete security is enabled
     */
    protected function hasDeleteSecurityRestrictions()
    {
        if (property_exists($this->modelClass, 'deleteSecurityRestrictions')) {
            return getPrivateProperty(new ($this->modelClass), 'deleteSecurityRestrictions');
        }

        return config('kompo-auth.security.default-delete-security-restrictions', true);
    }

    /**
     * Determines if the model has save security restrictions.
     * 
     * @return bool True if save security is enabled
     */
    protected function hasSaveSecurityRestrictions()
    {
        if (property_exists($this->modelClass, 'saveSecurityRestrictions')) {
            return getPrivateProperty(new ($this->modelClass), 'saveSecurityRestrictions');
        }

        return config('kompo-auth.security.default-save-security-restrictions', true);
    }

    /**
     * Determines if the model is restricted by team.
     * 
     * @return bool True if team restrictions apply
     */
    protected function massRestrictByTeam()
    {
        $restrictByTeam = false;

        if (property_exists($this->modelClass, 'restrictByTeam')) {
            $restrictByTeam = getPrivateProperty(new ($this->modelClass), 'restrictByTeam');
        } else {
            $restrictByTeam = config('kompo-auth.security.default-restrict-by-team', true);
        }

        if ($restrictByTeam && (!method_exists($this->modelClass, 'scopeSecurityForTeams') && !$this->getTeamIdColumn())) {
            $restrictByTeam = false;

            Log::error('The model ' . $this->modelClass . ' is not properly configured for team restrictions. For now it will not be restricted by team. Please implement scopeSecurityForTeams or ensure team_id column exists.');
        }

        return $restrictByTeam;
    }

    protected function individualRestrictByTeam($model)
    {
        $restrictByTeam = false;

        if (property_exists($model, 'restrictByTeam')) {
            $restrictByTeam = getPrivateProperty($model, 'restrictByTeam');
        } else {
            $restrictByTeam = config('kompo-auth.security.default-restrict-by-team', true);
        }

        if ($restrictByTeam && $this->getTeamOwnersIds($model) === false) {
            $restrictByTeam = false;

            Log::error('The model ' . $this->modelClass . ' is not properly configured for team restrictions. For now it will not be restricted by team. Please implement securityRelatedTeamIds or ensure team_id column exists for individual checks.');
        }

        return $restrictByTeam;
    }

    /**
     * Retrieves the team owner IDs for a given model.
     *
     * This method is used to restrict individual records by team. It checks for the
     * `securityRelatedTeamIds` method or the presence of a `team_id` column. If neither
     * is available, the record will not be restricted by team.
     *
     * @param mixed $model The model instance
     * @return mixed The team owner IDs or false if not configured properly
     */
    protected function getTeamOwnersIds($model)
    {
        if ($this->modelHasMethod($model, 'securityRelatedTeamIds')) {
            return callPrivateMethod($model, 'securityRelatedTeamIds');
        }

        if ($model::class == TeamModel::getClass()) {
            return $model->getKey();
        }

        if ($teamIdColumn = $this->getTeamIdColumn()) {
            return $model->{$teamIdColumn};
        }

        if (method_exists($model, 'team')) {
            return $model->team;
        }

        Log::error(sprintf(
            'Model %s does not have a method to retrieve team owner ID. Please implement getTeamOwnerId() or ensure team_id column exists.',
            $this->modelClass
        ));

        return false;
    }

    protected function getTeamIdColumn()
    {
        $column = 'team_id';

        if (property_exists($this->modelClass, 'TEAM_ID_COLUMN')) {
            $column = getPrivateProperty(new ($this->modelClass), 'TEAM_ID_COLUMN');
        }

        if (Schema::hasColumn((new ($this->modelClass))->getTable(), $column)) {
            return $column;
        }

        return null;
    }

    /**
     * Saves the model bypassing security checks.
     * 
     * @param mixed $model The model to save
     * @return bool The result of the save operation
     */
    public function systemSave($model)
    {
        $model->_bypassSecurity = true;
        $result = $model->save();

        return $result;
    }

    /**
     * Deletes the model bypassing security checks.
     * 
     * @param mixed $model The model to delete
     * @return bool The result of the delete operation
     */
    public function systemDelete($model)
    {
        $model->_bypassSecurity = true;
        $result = $model->delete();

        return $result;
    }

    /**
     * Lists the methods that can be called on the model.
     * 
     * @return array The list of callable methods
     */
    public function managableMethods()
    {
        return [
            'checkWritePermissions',
            'systemSave',
            'systemDelete',
        ];
    }
}