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
     * Check if model has read security restrictions
     */
    protected function hasReadSecurityRestrictions(): bool
    {
        if (property_exists($this->modelClass, 'readSecurityRestrictions')) {
            return getPrivateProperty(new ($this->modelClass), 'readSecurityRestrictions');
        }

        return config('kompo-auth.security.default-read-security-restrictions', true);
    }

    /**
     * Check if model has save/write security restrictions
     */
    protected function hasSaveSecurityRestrictions(): bool
    {
        if (property_exists($this->modelClass, 'saveSecurityRestrictions')) {
            return getPrivateProperty(new ($this->modelClass), 'saveSecurityRestrictions');
        }

        return config('kompo-auth.security.default-save-security-restrictions', true);
    }

    /**
     * Check if model has delete security restrictions
     */
    protected function hasDeleteSecurityRestrictions(): bool
    {
        if (property_exists($this->modelClass, 'deleteSecurityRestrictions')) {
            return getPrivateProperty(new ($this->modelClass), 'deleteSecurityRestrictions');
        }

        return config('kompo-auth.security.default-delete-security-restrictions', true);
    }
}
