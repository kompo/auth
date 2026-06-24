<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carousel slides for a permission's info modal. Each row is one page: a single
 * media (uploaded image/gif via `image`, or a scribehow.com guide via
 * `scribe_id`) plus a translatable `caption`.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('permission_info_slides')) {
            return;
        }

        Schema::create('permission_info_slides', function (Blueprint $table) {
            addMetaData($table);

            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedTinyInteger('media_type')->default(1); // PermissionInfoMediaTypeEnum::IMAGE
            $table->json('image')->nullable();
            $table->string('scribe_id')->nullable();
            $table->text('caption')->nullable();

            $table->index(['permission_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_info_slides');
    }
};
