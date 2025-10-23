<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('team_roles', function (Blueprint $table) {
            if (!Schema::hasColumn('team_roles', 'parent_team_role_id')) {
                $table->foreignId('parent_team_role_id')->nullable()->constrained('team_roles')->onDelete('set null');
            }
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
            $table->dropColumn('numeric_id');
        });
    }
};