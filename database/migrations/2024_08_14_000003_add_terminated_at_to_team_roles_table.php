<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('team_roles', function (Blueprint $table) {
            $table->timestamp('terminated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('team_roles', function (Blueprint $table) {
            $table->dropColumn('terminated_at');
            $table->dropColumn('suspended_at');
        });
    }
};