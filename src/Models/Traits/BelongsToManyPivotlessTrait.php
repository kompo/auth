<?php

namespace Kompo\Auth\Models\Traits;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait BelongsToManyPivotlessTrait
{
    public function belongsToManyPivotless(
        $related,
        $table = null,
        $foreignPivotKey = null,
        $relatedPivotKey = null,
        $parentKey = null,
        $relatedKey = null,
        $relation = null
    ) {
        if (is_null($relation)) {
            $relation = $this->guessBelongsToManyRelation();
        }

        $instance = $this->newRelatedInstance($related);

        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();

        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        if (is_null($table)) {
            $table = $this->joiningTable($related, $instance);
        }

        return new class(
            $instance->newQuery(),
            $this,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(),
            $relation
        ) extends BelongsToMany {
            // protected function hydratePivotRelation(array $models) {}

            protected function shouldSelect(array $columns = ['*'])
            {
                if ($columns == ['*']) {
                    $columns = [$this->related->getTable().'.*'];
                }
        
                return array_merge($columns, $this->aliasedPivotColumns());
            }

            protected function aliasedPivotColumns()
            {
                $defaults = [$this->foreignPivotKey];
        
                return collect(array_merge($defaults, $this->pivotColumns))->map(function ($column) {
                    return $this->qualifyPivotColumn($column).' as pivot_'.$column;
                })->unique()->all();
            }
        };
    }
}
