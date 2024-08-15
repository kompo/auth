<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kompo\Auth\Models\Teams\ProfileEnum;

return new class extends Migration
{
    public function up()
    {
        Schema::create('roles', function (Blueprint $table)
        {
            addedModifiedByColumns($table);
            
            $table->string('id')->primary();

            $table->string('name');
            $table->string('description')->nullable();
            $table->string('icon', 500)->nullable();
            $table->tinyInteger('profile')->default(ProfileEnum::GENERAL);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('roles');
    }
};