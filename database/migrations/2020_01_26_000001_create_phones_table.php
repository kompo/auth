<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePhonesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('phones', function (Blueprint $table) {

            addMetaData($table);
            
            $table->foreignId('team_id')->nullable()->constrained();

            $table->nullableMorphs('phonable');
            $table->tinyInteger('type_ph')->nullable();
            $table->string('number_ph');
            $table->string('extension_ph')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('phones');
    }
}
