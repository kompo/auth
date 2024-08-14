<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->tinyInteger('accept_roll_to_child')->nullable();
            $table->tinyInteger('accept_roll_to_neighbourg')->nullable();
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
            $table->dropColumn('accept_roll_to_child');
            $table->dropColumn('accept_roll_to_neighbourg');
        });
    }
};