<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        DB::unprepared('
            UPDATE roles SET accept_roll_to_child = 0 WHERE accept_roll_to_child IS NULL;
            UPDATE roles SET accept_roll_to_neighbourg = 0 WHERE accept_roll_to_neighbourg IS NULL;
        ');

        Schema::table('roles', function (Blueprint $table) {
            $table->tinyInteger('accept_roll_to_child')->nullable(false)->default(0)->change();
            $table->tinyInteger('accept_roll_to_neighbourg')->nullable(false)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->tinyIncrementsger('accept_roll_to_child')->nullable()->default(null)->change();
            $table->tinyInteger('accept_roll_to_neighbourg')->nullable()->default(null)->change();
        });
    }
};