<?php

namespace Kompo\Auth\Monitoring;

use Kompo\Auth\Models\User;
use Kompo\Komponents\Komponent;
use Kompo\Models\ModelBase;

class ModelChangesLog extends ModelBase
{
    // TODO Consider putting this to true, but we already have changed_at and this record in only for creating, not updating
    public $timestamps = false;

    protected $fillable = [
        'changeable_type',
        'changeable_id',
        'action',
        'columns_changed',
        'changed_by',
        'old_data',
        'changed_at',
        'new_data',
    ];

    protected $casts = [
        'columns_changed' => 'array',
        'action' => ChangeTypeEnum::class,
        'changed_at' => 'datetime',
        'old_data' => 'array',
        'new_data' => 'array',
    ];
    const CREATED_AT = 'changed_at';

    // RELATIONSHIPS
    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function changeable()
    {
        return $this->morphTo();
    }

    // SCOPES
    public function scopeContainOneOfColumns($query, $columns)
    {
        $query->where(function ($query) use ($columns) {
            collect($columns)->each(function ($column) use ($query) {
                $query->orWhereJsonContains('columns_changed', $column);
            });
        });
    }

    // CALCULATED FIELDS
    public function label()
    {
        return $this->changedBy->name . ' - ' . $this->changed_at->format('d/m/Y H:i');
    }

    public function getDataComparision()
    {
        $columns = $this->columns_changed;

        return collect($columns)->mapWithKeys(function ($column) {
            return [$column => $this->getColumnComparision($column)];
        });
    }

    public function getDataComparisionEls()
    {
        $columns = $this->columns_changed;

        return collect($columns)->mapWithKeys(function ($column) {
            return [$column => $this->getColumnComparisionElement($column)];
        });
    }

    public function getColumnComparision($column, $raw = false, $komponentAllowed = false)
    {
        $nextChangeVersion = $this->changeable->modelChanges()->where('id', '>', $this->id)
            ->whereJsonContains('columns_changed', $column)
            ->first();

        $nextValue = $nextChangeVersion?->old_data[$column] ?? $this->changeable->getAttribute($column);

        $pastValue = $this->old_data[$column] ?? null;

        $finalPastValue = $raw ? $pastValue : $this->changeable->getCastedLabel($column, $pastValue);
        $finalNextValue = $raw ? $nextValue : $this->changeable->getCastedLabel($column, $nextValue);

        return [
            'old' => $this->showableValue($finalPastValue, $komponentAllowed),
            'new' => $this->showableValue($finalNextValue, $komponentAllowed),
        ];
    }

    public function getColumnComparisionElement($column)
    {
        $comparision = $this->getColumnComparision($column, false, true);

        return [
            'old' => $comparision['old'] instanceof Komponent ? $comparision['old'] : _Html($comparision['old']),
            'new' => $comparision['new'] instanceof Komponent ? $comparision['new'] : _Html($comparision['new']),
        ];
    }

    protected function showableValue($value, $komponentAllowed = true)
    {
        if (!$value || in_array(gettype($value), ['string', 'integer', 'double']) || ($komponentAllowed && $value instanceof Komponent)) {
            return $value;
        }

        return __('events.this-kind-of-data-is-not-supported-to-preview');
    }
}
