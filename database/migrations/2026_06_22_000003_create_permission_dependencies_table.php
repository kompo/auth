<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentation-level dependencies between permissions: `required_permission_id`
 * is another permission a user needs in order to make practical use of
 * `permission_id`. Surfaced as chips in the permission info modal. Self-referencing
 * pivot, deduplicated per pair.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('permission_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->foreignId('required_permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['permission_id', 'required_permission_id'], 'perm_deps_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_dependencies');
    }
};
