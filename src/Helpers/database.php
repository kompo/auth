<?php 

if (!function_exists("addMetaData")) {
    function addMetaData($table)
    {
        $table->id();
        addedModifiedByColumns($table);
        $table->timestamps();
        $table->softDeletes();
    }
}

if (!function_exists("addedModifiedByColumns")) {
    function addedModifiedByColumns($table)
    {
        $table->foreignId('added_by')->nullable()->constrained('users');
        $table->foreignId('modified_by')->nullable()->constrained('users');
    }
}