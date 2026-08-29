<?php

namespace Kompo\Auth\Teams\Roles;

/**
 * Session mirror of the matrix view (selected roles, search, expanded sections).
 * The live form fields stay the source of truth; this only restores the view after a reboot.
 */
final class RolesMatrixView
{
    const KEY = 'roles-matrix.view';
    const DEFAULT_ROLES_NUM = 4;

    public static function roles(): array
    {
        $kept = array_values(array_intersect(self::storedRoles(), getRoles()->pluck('id')->all()));

        return $kept ?: self::defaultRoles();
    }

    public static function search(): string
    {
        return (string) session(self::KEY . '.search', '');
    }

    public static function expanded(): array
    {
        return (array) session(self::KEY . '.expanded', []);
    }

    public static function remember(array $patch): void
    {
        session()->put(self::KEY, array_merge((array) session(self::KEY, []), $patch));
    }

    public static function addRole($roleId): void
    {
        self::remember(['roles' => array_values(array_unique([...self::roles(), $roleId]))]);
    }

    public static function forgetRole($roleId): void
    {
        self::remember(['roles' => array_values(array_diff(self::storedRoles(), [$roleId]))]);
    }

    protected static function storedRoles(): array
    {
        return (array) session(self::KEY . '.roles', []);
    }

    protected static function defaultRoles(): array
    {
        return getRolesOrderedByRelevance()->take(self::DEFAULT_ROLES_NUM)->pluck('id')->all();
    }
}
