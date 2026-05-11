<?php

namespace Kompo\Auth\Teams\Security\Traits;

use Kompo\Auth\Teams\Security\SecurityMetadataRegistry;

/**
 * Per-operation restriction check. The OptsOutOfSecurity contract is checked
 * first via the registry; legacy `$readSecurityRestrictions` / etc. and config
 * fall through.
 */
trait SecurityConfigTrait
{
    /** Per-class, per-operation cache. Cleared at request end. */
    protected static $securityConfigCache = [];

    protected function hasReadSecurityRestrictions(): bool
    {
        return $this->getCachedSecurityConfig('read');
    }

    protected function hasSaveSecurityRestrictions(): bool
    {
        return $this->getCachedSecurityConfig('write');
    }

    protected function hasDeleteSecurityRestrictions(): bool
    {
        return $this->getCachedSecurityConfig('delete');
    }

    protected function getCachedSecurityConfig(string $operation): bool
    {
        $class = $this->modelClass;

        if (isset(static::$securityConfigCache[$class][$operation])) {
            return static::$securityConfigCache[$class][$operation];
        }

        // Contract takes precedence: OptsOutOfSecurity → false for the listed ops.
        $skipped = SecurityMetadataRegistry::for($class)['skippedOperations'] ?? [];
        if (in_array($operation, $skipped, true)) {
            return static::$securityConfigCache[$class][$operation] = false;
        }

        // Per-concern config: security.{read|write|delete}.enabled.
        // `kompoAuthSecurityConfig` handles the legacy flat fallback for one cycle.
        return static::$securityConfigCache[$class][$operation] =
            (bool) kompoAuthSecurityConfig("{$operation}.enabled", true);
    }

    public static function clearSecurityConfigCache(): void
    {
        static::$securityConfigCache = [];
    }
}
