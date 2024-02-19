<?php 

use Illuminate\Database\Eloquent\Relations\Relation;

if(!function_exists('findOrFailMorphModel')) {
    function findOrFailMorphModel($modelId, $modelType)
    {
        $model = Relation::morphMap()[$modelType];

        return $model::findOrFail($modelId); 
    }
}