<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('numeric_id');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->unique('numeric_id');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('numeric_id')->autoIncrement()->primary(false)->change();
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
            $table->dropColumn('numeric_id');
        });
    }
};