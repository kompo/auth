<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `permissions.supported_types` — bitmask of `PermissionTypeEnum` values the
 * permission accepts. Default `7` (READ|WRITE|ALL) keeps every existing row
 * behaving as before. DENY is a separate axis and is always supported.
 *
 * Narrowing this bitmask reduces:
 *   - the matrix-render iteration (fewer checkboxes per row)
 *   - `PermissionAccessIndex` expansion (fewer per-type Redis members)
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('permissions') || Schema::hasColumn('permissions', 'supported_types')) {
            return;
        }

        Schema::table('permissions', function (Blueprint $table) {
            $table->unsignedTinyInteger('supported_types')->default(7)->after('object_type');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasColumn('permissions', 'supported_types')) {
            return;
        }

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('supported_types');
        });
    }
};
