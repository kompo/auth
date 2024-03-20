<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAddressesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('addresses', function (Blueprint $table) {

            addMetaData($table);

            $table->foreignId('team_id')->nullable()->constrained();

            $table->nullableMorphs('addressable');
            $table->foreignId('address_type_id')->nullable()->constrained();
            $table->integer('type_ad')->nullable(); //when not a contact
            $table->text('address1');
            $table->string('apt_or_suite')->nullable();
            $table->string('postal_code')->nullable(); //because some adresses don't have one (outside canada)
            $table->string('city')->nullable(); //some adresses don't have a city... wtf google
            $table->string('state');
            $table->string('country');
            $table->string('street')->nullable();
            $table->string('street_number')->nullable();
            $table->decimal('lat', 16, 12)->nullable();
            $table->decimal('lng', 16, 12)->nullable();
            $table->string('external_id')->nullable();
            $table->string('description_ad')->nullable();
            $table->integer('order_ad')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('addresses');
    }
}
