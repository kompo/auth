<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the translatable Read and Write description columns used by the
 * permission info modal.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->text('permission_description_read')->nullable()->after('permission_description');
            $table->text('permission_description_write')->nullable()->after('permission_description_read');
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('permission_description_read');
            $table->dropColumn('permission_description_write');
        });
    }
};
