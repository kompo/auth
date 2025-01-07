<?php

namespace Kompo\Auth\Models\Traits;

use Kompo\Auth\Monitoring\ChangeTypeEnum;
use Kompo\Auth\Monitoring\ModelChangesLog;

trait HasManyModelChanges 
{
    public static function bootHasManyModelChanges()
    {
        static::saving(function ($model) {
            if ($model->getKey() && $model->isDirty()) {
                ModelChangesLog::create([
                    'changeable_type' => $model->getMorphClass(),
                    'changeable_id' => $model->getKey(),
                    'action' => $model->getKey() ? ChangeTypeEnum::UPDATE : ChangeTypeEnum::CREATE,
                    'columns_changed' => array_keys($model->getDirty()),
                    'changed_by' => auth()->id(),
                    'old_data' => collect(array_keys($model->getDirty()))->intersect($model->getColumnsToSaveOldData())
                        ->mapWithKeys(fn($col) => [$col => $model->getOriginal($col)])->toArray(),
                    'new_data' => collect(array_keys($model->getDirty()))->mapWithKeys(fn($col) => [$col => $model->getAttribute($col)])->toArray(),
                    'changed_at' => now()
                ]);
            }
        });
    }

    public function modelChanges()
    {
        return $this->morphMany(ModelChangesLog::class, 'changeable');
    }

    public function lastChangeForSomeOfColumns($columns)
    {
        return $this->modelChanges()->containOneOfColumns($columns)->latest()->first();
    }

    public function getColumnsToSaveOldData()
    {
        if (!property_exists($this, 'logChangesColumns')) return [];

        if (is_string($this->logChangesColumns) && $this->logChangesColumns == '*') {
            return array_keys($this->getAttributes());
        }

        return $this->logChangesColumns ?: [];
    }
}