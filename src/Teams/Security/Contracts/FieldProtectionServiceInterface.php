<?php

namespace Kompo\Auth\Teams\Security\Contracts;

/**
 * Contract for field-level (column and relationship) protection. Single mode —
 * batch on retrieval, driven by `BatchPermissionService`. The pure service
 * lives in `Teams\Security\FieldProtectionService`; per-request caching lives
 * in the decorator `Teams\Cache\CachedFieldProtectionService`.
 */
interface FieldProtectionServiceInterface
{
    public function hasPermissionForProtectionKey($model, string $permissionKey): bool;

    public function hideSensitiveFields($model, array $sensibleColumns): void;

    public function applyRelationshipBlocking($model, array $relationships): void;

    public function cleanupModelTracking(string $modelKey): void;
}
