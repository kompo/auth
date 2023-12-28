<?php 

function addMetaData($table)
{
    $table->id();
	addedModifiedByColumns($table);
    $table->timestamps();
    $table->softDeletes();
}

function addedModifiedByColumns($table)
{
	$table->foreignId('added_by')->nullable()->constrained('users');
    $table->foreignId('modified_by')->nullable()->constrained('users');
}