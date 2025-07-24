<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('from_system')->default(false);

            $table->integer('max_assignments_per_team')->nullable();
        });

    }

    public function down()
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('from_system');

            $table->dropColumn('max_assignments_per_team');
        });
    }
};