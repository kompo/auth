<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotificationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('notifications', function (Blueprint $table)
        {
            addMetaData($table);
            
            $table->foreignId('team_id')->constrained();
            $table->foreignId('notifier_id')->nullable()->constrained('users');
            $table->foreignId('user_id')->constrained();
            $table->integer('type')->nullable();
            $table->integer('status')->nullable();
            $table->datetime('reminder_at')->nullable();
            $table->morphs('about');
            $table->dateTime('seen_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('notifications');
    }
}
