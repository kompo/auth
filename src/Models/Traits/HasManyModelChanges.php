<?php

namespace Kompo\Auth\Models\Traits;

use Kompo\Auth\Monitoring\ModelChangesLog;

trait HasManyModelChanges 
{
    public function modelChanges()
    {
        return $this->morphMany(ModelChangesLog::class, 'changeable');
    }

    public function lastChangeForSomeOfColumns($columns)
    {
        $initialQuery = $this->modelChanges();

        foreach($columns as $key => $column){
            if($key == 0){
                $initialQuery->whereJsonContains('columns_changed', $column);
            } else {
                $initialQuery->orWhereJsonContains('columns_changed', $column);
            }
        }

        return $initialQuery->latest()->first();
    }

    public function getColumnsToSaveOldData()
    {
        if (!property_exists($this, 'logChangesColumns')) return [];

        if (is_string($this->logChangesColumns) && $this->logChangesColumns == '*') {
            return $this->logChangesColumns;
        }

        return $this->logChangesColumns || [];
    }
}