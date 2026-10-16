<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kompo\Auth\Models\Teams\PermissionDependencyTypeEnum;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('permission_dependencies', function (Blueprint $table) {
            $table->unsignedTinyInteger('dependency_type')->default(PermissionDependencyTypeEnum::STRUCTURAL->value)->after('required_permission_id');
        });

        // Rows written before this column were documentation only, never enforced.
        DB::table('permission_dependencies')->update(['dependency_type' => PermissionDependencyTypeEnum::RECOMMENDED->value]);
    }

    public function down(): void
    {
        Schema::table('permission_dependencies', function (Blueprint $table) {
            $table->dropColumn('dependency_type');
        });
    }
};
