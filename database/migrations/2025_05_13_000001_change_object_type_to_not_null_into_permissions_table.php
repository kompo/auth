<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kompo\Auth\Models\Teams\PermissionObjectTypeEnum;

return new class extends Migration
{
    public function up()
    {
        DB::unprepared('
            UPDATE permissions SET object_type = '.PermissionObjectTypeEnum::GENERAL->value.' WHERE object_type IS NULL;
        ');

        Schema::table('permissions', function (Blueprint $table) {
            $table->tinyInteger('object_type')->default(PermissionObjectTypeEnum::GENERAL->value)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->tinyInteger('object_type')->default(null)->nullable()->change();
        });
    }
};