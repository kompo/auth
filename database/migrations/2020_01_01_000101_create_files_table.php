<?php

use App\Models\Library\File;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kompo\Auth\Models\Files\FileVisibilityEnum;

class CreateFilesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('files', function (Blueprint $table) {
            addMetaData($table);

            $table->foreignId('team_id')->nullable()->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->nullableMorphs('fileable'); //nullable in case our app accepts files without attached parent

            $table->string('path');
            $table->string('name')->nullable();
            $table->string('mime_type')->nullable();
            $table->bigInteger('size')->nullable();

            $table->tinyInteger('visibility')->default(FileVisibilityEnum::PRIVATE);
            $table->tinyInteger('subtype')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('files');
    }
}
