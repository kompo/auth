<?php

namespace Kompo\Auth\Contracts\Security;

/**
 * The permission key the model is gated by. Replaces the `$permissionKey`
 * property + `getPermissionKey()` magic method + `class_basename` fallback.
 *
 * Absence of the contract → default is `class_basename(static::class)`.
 */
interface HasPermissionKey
{
    public function getPermissionKey(): string;
}
