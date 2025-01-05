<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kompo\Auth\Models\Teams\ProfileEnum;

return new class extends Migration
{
    public function up()
    {
        Schema::table('roles', function (Blueprint $table)
        {
            $table->tinyInteger('just_one_person_per_team')->nullable();
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
            $table->dropColumn('just_one_person_per_team');
        });
    }
};