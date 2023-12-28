<?php 

use Illuminate\Database\Eloquent\Relations\Relation;

function findOrFailMorphModel($modelId, $modelType)
{
	$model = Relation::morphMap()[$modelType];

    return $model::findOrFail($modelId); 
}