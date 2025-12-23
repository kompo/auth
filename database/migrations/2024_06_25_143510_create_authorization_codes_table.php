<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('authorization_codes', function (Blueprint $table) {
            addMetaData($table);

            $table->ipAddress()->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('code');
            $table->string('type');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authorization_codes');
    }
};
