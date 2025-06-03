<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

if (!function_exists('hasColumnCached')) {
    function hasColumnCached(string $table, string $column)
    {
        $cacheKey = "has_column_{$table}_{$column}";

        return Cache::remember($cacheKey, 3600, function () use ($table, $column) {
            return Schema::hasColumn($table, $column);
        });
    }
}
