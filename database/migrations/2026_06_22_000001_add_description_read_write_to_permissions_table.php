<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured Read / Write descriptions for a permission, so the info modal can
 * show two clean sections instead of parsing the single `permission_description`
 * field. Both are translatable JSON (HasTranslations). Existing rows keep their
 * legacy `permission_description`, which the modal still parses as a fallback
 * until the new columns are filled (editor or seeder).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        Schema::table('permissions', function (Blueprint $table) {
            if (!Schema::hasColumn('permissions', 'permission_description_read')) {
                $table->text('permission_description_read')->nullable()->after('permission_description');
            }

            if (!Schema::hasColumn('permissions', 'permission_description_write')) {
                $table->text('permission_description_write')->nullable()->after('permission_description_read');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        Schema::table('permissions', function (Blueprint $table) {
            foreach (['permission_description_read', 'permission_description_write'] as $column) {
                if (Schema::hasColumn('permissions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
