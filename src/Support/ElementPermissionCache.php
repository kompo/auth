<?php

namespace Kompo\Auth\Support;

class ElementPermissionCache
{
    protected static array $cache = [];

    public static function get(string $key): ?bool
    {
        return static::$cache[$key] ?? null;
    }

    public static function set(string $key, bool $value): void
    {
        static::$cache[$key] = $value;
    }

    public static function remember(string $key, callable $callback): bool
    {
        if (!array_key_exists($key, static::$cache)) {
            static::$cache[$key] = (bool) $callback();
        }

        return static::$cache[$key];
    }

    public static function clear(): void
    {
        static::$cache = [];
    }
}
