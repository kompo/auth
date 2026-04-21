<?php

namespace Kompo\Auth\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SchemaCache
{
    public function hasColumn(string $table, string $column): bool
    {
        return (bool) Cache::remember(
            "has_column_{$table}_{$column}",
            3600,
            fn() => Schema::hasColumn($table, $column)
        );
    }
}
