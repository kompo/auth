<?php

namespace Kompo\Auth\Models\Plugins\Services\Traits;

/**
 * Trait with common model utility methods
 *
 * Provides reusable methods for working with models across security services
 */
trait ModelHelperTrait
{
    /**
     * Cached table names per model class — avoids instantiating a model just to get the table.
     */
    protected static $modelTableCache = [];

    /**
     * Generate a unique key for a model instance
     */
    protected function getModelKey($model): string
    {
        return get_class($model) . '_' . ($model->getKey() ?? spl_object_hash($model));
    }

    /**
     * Get model table name (cached per class)
     */
    protected function getModelTable(): string
    {
        if (!isset(static::$modelTableCache[$this->modelClass])) {
            static::$modelTableCache[$this->modelClass] = (new ($this->modelClass))->getTable();
        }

        return static::$modelTableCache[$this->modelClass];
    }

    /**
     * Check if model or modelClass has a method
     */
    protected function modelHasMethod($modelOrClass, ?string $method = null): bool
    {
        if (is_string($modelOrClass) && is_string($method)) {
            return method_exists($modelOrClass, $method);
        } elseif (is_object($modelOrClass) && is_string($method)) {
            return method_exists($modelOrClass, $method);
        } else {
            // When called with single parameter from instance context
            return method_exists($this->modelClass ?? $modelOrClass, $modelOrClass);
        }
    }

    /**
     * Check if model has a property
     */
    protected function modelHasProperty($model, string $property): bool
    {
        return property_exists($model, $property);
    }
}
