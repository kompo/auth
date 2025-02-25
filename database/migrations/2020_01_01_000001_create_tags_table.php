<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kompo\Auth\Models\Tags\Tag;
use Kompo\Auth\Models\Tags\TagContextEnum;

class CreateTagsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tags', function (Blueprint $table) {
            addMetaData($table);
            $table->foreignId('tag_id')->nullable()->constrained('tags');
            $table->foreignId('team_id')->nullable()->constrained();

            $table->string('name');
            $table->string('description', 500)->nullable();

            $table->tinyInteger('tag_type')->nullable();
            $table->tinyInteger('context')->default(TagContextEnum::TEAM);
        });

        Schema::create('taggable_tag', function (Blueprint $table) {
            $table->id();
            $table->morphs('taggable');
            $table->foreignId('tag_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('taggable_tag');
        Schema::dropIfExists('tags');
    }
}
