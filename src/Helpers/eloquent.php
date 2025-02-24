<?php 

use Illuminate\Database\Eloquent\Relations\Relation;

/* SELECT OPTIONS */
if (!function_exists("performSearchOptions")) {
	function performSearchOptions($scope, $nameColumn, $queryBuilder)
	{
		$search = request('search');

		$options = $queryBuilder->{$scope}($search)->limit(40)->get();

		return $options->pluck($nameColumn, 'id');
	}
}

if (!function_exists("performSearchOptions")) {
	function performRetrieveOption($model, $nameColumn = null)
	{
		if (!$model) {
			return [];
		}

		return [
			$model->id => $model->{$nameColumn ?: $model::SEARCHABLE_NAME_ATTRIBUTE},
		];
	}
}

/* MORPHS STRINGS */
if (!function_exists("getModelFromMorphable")) {
	function getModelFromMorphable($morphableType, $morphableId)
	{
		$modelClass = Relation::morphMap()[$morphableType];
		return $modelClass::findOrFail($morphableId);
	}
}

if (!function_exists("performSearchOptions")) {
	function getModelFromMorphString($morphString, $delimiter = '|')
	{
		[$morphableType, $morphableId] = explode('|', $morphString);

		return getModelFromMorphable($morphableType, $morphableId);
	}
}