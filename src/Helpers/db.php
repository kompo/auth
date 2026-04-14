<?php

use Kompo\Auth\Support\SchemaCache;

if (!function_exists('hasColumnCached')) {
    function hasColumnCached(string $table, string $column)
    {
        return app(SchemaCache::class)->hasColumn($table, $column);
    }
}
