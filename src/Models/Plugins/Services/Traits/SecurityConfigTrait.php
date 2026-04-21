<?php

namespace Kompo\Auth\Models\Plugins\Services\Traits;

/**
 * Trait for security configuration checks
 *
 * Provides reusable methods for checking security restriction settings
 */
trait SecurityConfigTrait
{
    /**
     * Cached config values per model class — avoids instantiating models for property checks.
     * Format: ['App\Models\Foo' => ['read' => bool, 'save' => bool, 'delete' => bool]]
     */
    protected static $securityConfigCache = [];

    /**
     * Check if model has read security restrictions
     */
    protected function hasReadSecurityRestrictions(): bool
    {
        return $this->getCachedSecurityConfig('read', 'readSecurityRestrictions', 'default-read-security-restrictions');
    }

    /**
     * Check if model has save/write security restrictions
     */
    protected function hasSaveSecurityRestrictions(): bool
    {
        return $this->getCachedSecurityConfig('save', 'saveSecurityRestrictions', 'default-save-security-restrictions');
    }

    /**
     * Check if model has delete security restrictions
     */
    protected function hasDeleteSecurityRestrictions(): bool
    {
        return $this->getCachedSecurityConfig('delete', 'deleteSecurityRestrictions', 'default-delete-security-restrictions');
    }

    /**
     * Get a security config value, cached per model class and config key.
     */
    protected function getCachedSecurityConfig(string $cacheKey, string $property, string $configKey): bool
    {
        $class = $this->modelClass;

        if (!isset(static::$securityConfigCache[$class][$cacheKey])) {
            if (property_exists($class, $property)) {
                static::$securityConfigCache[$class][$cacheKey] = getPrivateProperty(new $class, $property);
            } else {
                static::$securityConfigCache[$class][$cacheKey] = config("kompo-auth.security.{$configKey}", true);
            }
        }

        return static::$securityConfigCache[$class][$cacheKey];
    }

    public static function clearSecurityConfigCache(): void
    {
        static::$securityConfigCache = [];
    }
}
