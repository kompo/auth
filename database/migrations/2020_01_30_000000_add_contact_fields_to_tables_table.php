<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //$this->addContactFieldsColumns('users');
        $this->addContactFieldsColumns('teams');
    }

    protected function addContactFieldsColumns($table)
    {
        Schema::table($table, function (Blueprint $table) {
            $table->foreignId('primary_email_id')->nullable()->constrained('emails');
            $table->foreignId('primary_phone_id')->nullable()->constrained('phones');
            $table->foreignId('primary_billing_address_id')->nullable()->constrained('addresses');
            $table->foreignId('primary_shipping_address_id')->nullable()->constrained('addresses');
        });
    }



    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //$this->dropContactFieldsColumns('users');
        $this->dropContactFieldsColumns('teams');
    }

    protected function dropContactFieldsColumns($table)
    {
        Schema::table($table, function (Blueprint $table) {
            $table->dropForeign(['primary_billing_address_id']);
            $table->dropColumn('primary_billing_address_id');
            $table->dropForeign(['primary_shipping_address_id']);
            $table->dropColumn('primary_shipping_address_id');
            $table->dropForeign(['primary_phone_id']);
            $table->dropColumn('primary_phone_id');
            $table->dropForeign(['primary_email_id']);
            $table->dropColumn('primary_email_id');
        });
    }
};
